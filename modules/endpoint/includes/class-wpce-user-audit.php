<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * The user side of the endpoint payload: how many accounts a site has, who
 * they are, what they are allowed to do — and which of them look like they
 * were not put there by the site's owner.
 *
 * On the "phishing detection" question: there is no clean way to decide that a
 * given address *is* a phishing address, and anything claiming otherwise is
 * guessing. What can be checked cleanly is whether an address is real and
 * whether the account around it fits the shape of the accounts attackers leave
 * behind after a break-in — a brand-new administrator, an address on a
 * throwaway mailbox provider, a domain that can't receive mail at all, a domain
 * that is one character away from the site's own, capabilities without a role.
 * Each of those is a *signal*, not a verdict; they are weighted and summed, and
 * the result is reported as 'watch' or 'high' so a human can go and look. None
 * of them is proof of a compromise, and a legitimate account can trip one.
 */
class WPCE_User_Audit
{
	// Hard cap on how many accounts the /users route ships. Privileged accounts
	// are selected first, so a site with more users than this still sends every
	// administrator — the cap only truncates the ordinary-subscriber tail.
	const MAX_USERS = 300;

	// Cap on the accounts the (frequently polled) status summary scans for
	// flags: administrators plus anyone registered in the last RECENT_DAYS.
	const MAX_SCAN = 100;

	// How recently an administrator must have been created to be worth a flag.
	const RECENT_DAYS = 30;

	// The summary is recomputed at most this often; the hub polls status every
	// few minutes and several hubs may poll the same site.
	const SUMMARY_TRANSIENT = 'wpce_user_summary';
	const SUMMARY_TTL       = 300;

	// MX/A lookups per mail domain, cached so opening the same site's user
	// dialog twice doesn't re-resolve everything.
	const DNS_TRANSIENT = 'wpce_user_audit_dns';
	const DNS_TTL       = 43200;

	/** @var array */
	private $dns_cache = null;

	/** @var bool */
	private $dns_dirty = false;

	/** @var array */
	private $role_names = null;

	/**
	 * Mailbox providers that hand out throwaway addresses. An account on one of
	 * these is not automatically malicious (people do use them to sign up), but
	 * an *administrator* on one almost never is legitimate.
	 */
	private static function disposable_domains(): array
	{
		return [
			'0-mail.com', '10minutemail.com', '20minutemail.com', '33mail.com',
			'anonbox.net', 'byom.de', 'dispostable.com', 'discard.email',
			'emailondeck.com', 'fakeinbox.com', 'fakemailgenerator.com',
			'getairmail.com', 'getnada.com', 'guerrillamail.com', 'guerrillamail.net',
			'guerrillamailblock.com', 'inboxbear.com', 'jetable.org', 'mailcatch.com',
			'maildrop.cc', 'mailexpire.com', 'mailinator.com', 'mailnesia.com',
			'mailsac.com', 'mintemail.com', 'mohmal.com', 'moakt.com',
			'mytemp.email', 'nowmymail.com', 'sharklasers.com', 'spam4.me',
			'spamgourmet.com', 'temp-mail.org', 'tempail.com', 'tempinbox.com',
			'tempmail.net', 'tempmailaddress.com', 'throwawaymail.com',
			'trashmail.com', 'trashmail.de', 'yopmail.com', 'yopmail.fr',
		];
	}

	// Role slugs that carry administrator-grade privileges on *this* site,
	// read from the site's own role definitions so custom manager roles count
	// too rather than only the stock 'administrator'.
	private function privileged_roles(): array
	{
		$slugs = [];
		foreach (wp_roles()->roles as $slug => $role) {
			$caps = isset($role['capabilities']) ? $role['capabilities'] : [];
			if (! empty($caps['manage_options']) || ! empty($caps['edit_users']) || ! empty($caps['promote_users'])) {
				$slugs[] = $slug;
			}
		}
		return $slugs ? $slugs : ['administrator'];
	}

	private function role_label(string $slug): string
	{
		if (null === $this->role_names) {
			$this->role_names = wp_roles()->get_names();
		}
		if (isset($this->role_names[$slug])) {
			return translate_user_role($this->role_names[$slug]);
		}
		// A role the site no longer defines — the account keeps the slug and
		// silently loses its capabilities (or keeps them, if they were set
		// directly on the user). Worth showing verbatim.
		return $slug;
	}

	private function is_privileged(WP_User $user): bool
	{
		return $user->has_cap('manage_options') || $user->has_cap('edit_users') || $user->has_cap('promote_users');
	}

	/**
	 * The counts the status payload carries: enough for the hub's table cell and
	 * its "look at this site" indicator, without shipping any personal data on
	 * every poll. Cached briefly — count_users() and the flag scan are cheap but
	 * not free, and this route is polled on a schedule.
	 */
	public function summary(): array
	{
		$cached = get_transient(self::SUMMARY_TRANSIENT);
		if (is_array($cached)) {
			return $cached;
		}

		$counts     = count_users();
		$privileged = $this->privileged_roles();

		$admins = 0;
		foreach ($privileged as $slug) {
			if (! empty($counts['avail_roles'][$slug])) {
				$admins += (int) $counts['avail_roles'][$slug];
			}
		}

		// Bounded scan: the privileged accounts (the ones worth worrying about)
		// plus everyone who registered recently (where a freshly injected
		// account would be). Not every user — a site with 50k subscribers must
		// not pay for a full table walk every few minutes.
		//
		// DNS is deliberately left out here: a slow or blocked resolver would
		// add seconds to a route the hub polls on a schedule (and gives up on
		// after ten). The consequence is that the summary can grade an account
		// milder than the /users route does — never the other way round, since
		// the deliverability check can only add a flag. Such an account is
		// still counted; opening the list is what runs the full check.
		$scan = get_users([
			'role__in' => $privileged,
			'number'   => self::MAX_SCAN,
			'orderby'  => 'ID',
		]);
		$seen = [];
		foreach ($scan as $user) {
			$seen[$user->ID] = true;
		}
		$recent = get_users([
			'number'     => self::MAX_SCAN,
			'exclude'    => array_keys($seen),
			'date_query' => [['after' => gmdate('Y-m-d H:i:s', time() - (self::RECENT_DAYS * DAY_IN_SECONDS))]],
		]);

		$flagged = 0;
		$high    = 0;
		foreach (array_merge($scan, $recent) as $user) {
			$level = $this->level($this->flags($user, false), $this->is_privileged($user));
			if ('none' === $level) {
				continue;
			}
			$flagged++;
			if ('high' === $level) {
				$high++;
			}
		}

		$summary = [
			'users_total'   => isset($counts['total_users']) ? (int) $counts['total_users'] : count($scan),
			'users_admins'  => $admins,
			// Accounts carrying any signal, and the subset that carries enough
			// of them to be worth treating as a possible break-in. The hub
			// colors its row indicator by the second number.
			'users_flagged' => $flagged,
			'users_high'    => $high,
		];

		set_transient(self::SUMMARY_TRANSIENT, $summary, self::SUMMARY_TTL);

		return $summary;
	}

	/**
	 * The full account list for the hub's Users dialog: privileged accounts
	 * first (they are what someone opening this dialog came to check), then the
	 * most recently registered, capped at MAX_USERS.
	 */
	public function users(): array
	{
		$privileged = $this->privileged_roles();

		$admins = get_users([
			'role__in' => $privileged,
			'number'   => self::MAX_USERS,
			'orderby'  => 'ID',
		]);

		$exclude = [];
		foreach ($admins as $user) {
			$exclude[] = $user->ID;
		}

		$remaining = self::MAX_USERS - count($admins);
		$others    = $remaining > 0 ? get_users([
			'number'  => $remaining,
			'exclude' => $exclude,
			'orderby' => 'registered',
			'order'   => 'DESC',
		]) : [];

		$dns  = ! defined('WPCE_USER_AUDIT_DNS') || WPCE_USER_AUDIT_DNS;
		$list = [];
		foreach (array_merge($admins, $others) as $user) {
			$list[] = $this->describe($user, $dns);
		}
		$this->persist_dns_cache();

		// Worst first, then privileged, then by name — the two accounts someone
		// needs to see are at the top without scrolling.
		$rank = ['high' => 0, 'watch' => 1, 'none' => 2];
		usort($list, function (array $a, array $b) use ($rank): int {
			if ($a['level'] !== $b['level']) {
				return $rank[$a['level']] - $rank[$b['level']];
			}
			if ($a['privileged'] !== $b['privileged']) {
				return $a['privileged'] ? -1 : 1;
			}
			return strcasecmp($a['login'], $b['login']);
		});

		$counts = count_users();
		$total  = isset($counts['total_users']) ? (int) $counts['total_users'] : count($list);

		return [
			'users'     => $list,
			'total'     => $total,
			'truncated' => $total > count($list),
		];
	}

	private function describe(WP_User $user, bool $with_dns): array
	{
		$roles = [];
		foreach ((array) $user->roles as $slug) {
			$roles[] = $this->role_label($slug);
		}

		$privileged = $this->is_privileged($user);
		$flags      = $this->flags($user, $with_dns);

		return [
			'id'            => (int) $user->ID,
			'login'         => $user->user_login,
			'name'          => $user->display_name,
			'email'         => $user->user_email,
			'roles'         => $roles,
			'privileged'    => $privileged,
			'super_admin'   => is_multisite() && is_super_admin($user->ID),
			'registered'    => substr((string) $user->user_registered, 0, 10),
			'registered_ts' => (int) strtotime($user->user_registered . ' UTC'),
			// Labels only — the weights are an implementation detail of level().
			'flags'         => wp_list_pluck($flags, 'label'),
			'level'         => $this->level($flags, $privileged),
		];
	}

	/**
	 * The individual signals against one account, each as
	 * ['label' => human text, 'weight' => how much it should count].
	 */
	private function flags(WP_User $user, bool $with_dns): array
	{
		$flags      = [];
		$email      = (string) $user->user_email;
		$domain     = strtolower((string) substr((string) strrchr($email, '@'), 1));
		$privileged = $this->is_privileged($user);

		if ('' === trim($email) || ! is_email($email)) {
			$flags[] = ['label' => 'No valid email address on the account', 'weight' => 2];
			$domain  = '';
		}

		if ('' !== $domain) {
			if (in_array($domain, self::disposable_domains(), true)) {
				$flags[] = ['label' => 'Throwaway mailbox provider (' . $domain . ')', 'weight' => 2];
			}

			// A punycode/non-ASCII domain is legitimate in general, but as the
			// mail domain of an account on an English-language site it is far
			// more often a homoglyph of a real one (аdmin.com with a Cyrillic a).
			if (0 === strpos($domain, 'xn--') || preg_match('/[^\x20-\x7e]/', $domain)) {
				$flags[] = ['label' => 'Internationalized (punycode) email domain — often a lookalike', 'weight' => 2];
			}

			// One or two characters away from the site's own domain: the classic
			// "looks right at a glance" spoof (exampIe.com for example.com).
			$site = $this->site_domain();
			if ('' !== $site && $domain !== $site) {
				$distance = levenshtein($domain, $site);
				if ($distance > 0 && $distance <= 2) {
					$flags[] = ['label' => 'Email domain imitates this site\'s domain (' . $domain . ' vs ' . $site . ')', 'weight' => 2];
				}
			}

			// The one genuinely objective check available: a domain with no MX
			// and no A record cannot receive mail, so nobody owns that address.
			if ($with_dns && ! $this->domain_accepts_mail($domain)) {
				$flags[] = ['label' => 'Email domain cannot receive mail (no MX or A record)', 'weight' => 2];
			}
		}

		if ($privileged) {
			// Deliberately light on its own: agencies add administrators all the
			// time, and a new site is *all* new administrators. It earns its
			// weight in combination with something else about the address.
			$registered = strtotime($user->user_registered . ' UTC');
			if ($registered && (time() - $registered) < (self::RECENT_DAYS * DAY_IN_SECONDS)) {
				$days    = max(1, (int) floor((time() - $registered) / DAY_IN_SECONDS));
				$flags[] = ['label' => 'Administrator account created ' . $days . ' ' . (1 === $days ? 'day' : 'days') . ' ago', 'weight' => 1];
			}

			// Capabilities granted straight onto the user record instead of
			// through a role is how a backdoor account usually hides from the
			// Users screen's role filter.
			if (! $user->roles) {
				$flags[] = ['label' => 'Administrator capabilities without any role assigned', 'weight' => 3];
			}
		}

		foreach ((array) $user->roles as $slug) {
			if (! wp_roles()->is_role($slug)) {
				$flags[] = ['label' => 'Account holds a role this site does not define (' . $slug . ')', 'weight' => 2];
			}
		}

		if ($this->looks_generated($user->user_login)) {
			$flags[] = ['label' => 'Username looks machine-generated', 'weight' => 1];
		}

		return $flags;
	}

	/**
	 * Turns the signals into the three states the hub renders: 'none', 'watch'
	 * (something is odd — worth a look) and 'high' (fits the shape of an account
	 * left behind by an intruder). A privileged account counts for one more than
	 * the same flags on a subscriber, because that is the account an attacker
	 * actually wants — but only once something substantive is already against
	 * it, so that a single soft flag (a new administrator, an odd-looking
	 * username) stays at 'watch' where it belongs.
	 */
	private function level(array $flags, bool $privileged): string
	{
		$score = 0;
		foreach ($flags as $flag) {
			$score += $flag['weight'];
		}
		if ($score >= 2 && $privileged) {
			$score++;
		}

		if ($score >= 3) {
			return 'high';
		}
		return $score > 0 ? 'watch' : 'none';
	}

	// Long, vowel-starved logins with digits mixed in ('x7f2kqzw', 'a8fj2h9d')
	// are what account-creation payloads produce; real people's usernames read
	// like words or names. The digit requirement is what keeps ordinary
	// consonant-heavy logins (initials plus a surname, say) out of it.
	private function looks_generated(string $login): bool
	{
		$name = strtolower(preg_replace('/[^a-z0-9]/i', '', $login));
		if (strlen($name) < 8 || ! preg_match('/[0-9]/', $name)) {
			return false;
		}
		$letters = preg_replace('/[^a-z]/', '', $name);
		if (strlen($letters) < 4) {
			// Eight characters, almost all digits.
			return true;
		}
		return (preg_match_all('/[aeiou]/', $letters) / strlen($letters)) < 0.2;
	}

	private function site_domain(): string
	{
		$host = wp_parse_url(home_url(), PHP_URL_HOST);
		if (! $host) {
			return '';
		}
		return preg_replace('/^www\./i', '', strtolower($host));
	}

	// Whether $domain has an MX (or, per the SMTP fallback rule, an A) record.
	// Answers true whenever it cannot tell — a resolver that is unavailable or
	// blocked must never turn every account on the site red.
	private function domain_accepts_mail(string $domain): bool
	{
		if (! function_exists('checkdnsrr')) {
			return true;
		}

		if (null === $this->dns_cache) {
			$stored          = get_transient(self::DNS_TRANSIENT);
			$this->dns_cache = is_array($stored) ? $stored : [];
		}
		if (isset($this->dns_cache[$domain])) {
			return (bool) $this->dns_cache[$domain];
		}

		$ok = checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A');

		$this->dns_cache[$domain] = $ok;
		$this->dns_dirty          = true;

		return $ok;
	}

	private function persist_dns_cache()
	{
		if ($this->dns_dirty) {
			set_transient(self::DNS_TRANSIENT, $this->dns_cache, self::DNS_TTL);
			$this->dns_dirty = false;
		}
	}
}
