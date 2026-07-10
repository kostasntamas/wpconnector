/*! lilac-editor (@lilac-wysiwyg/core) v0.5.0 | MIT License | https://github.com/maifeeulasad/lilac | bundled with esbuild for WP Connector Hub */
var LilacWysiwyg = (() => {
	var __defProp = Object.defineProperty;
	var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
	var __getOwnPropNames = Object.getOwnPropertyNames;
	var __hasOwnProp = Object.prototype.hasOwnProperty;
	var __export = (target, all) => {
		for (var name in all) __defProp(target, name, { get: all[name], enumerable: true });
	};
	var __copyProps = (to, from, except, desc) => {
		if ((from && typeof from === 'object') || typeof from === 'function') {
			for (let key of __getOwnPropNames(from))
				if (!__hasOwnProp.call(to, key) && key !== except)
					__defProp(to, key, {
						get: () => from[key],
						enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable,
					});
		}
		return to;
	};
	var __toCommonJS = (mod) => __copyProps(__defProp({}, '__esModule', { value: true }), mod);

	// dist/core/index.js
	var index_exports = {};
	__export(index_exports, {
		LilacEditor: () => LilacEditor,
		PluginManager: () => PluginManager,
		Toolbar: () => Toolbar,
		cn: () => cn,
		debounce: () => debounce,
		emojiPlugin: () => emojiPlugin,
		executeFormatCommand: () => executeFormatCommand,
		extractTextFromHtml: () => extractTextFromHtml,
		formatCommands: () => formatCommands,
		getActiveFormats: () => getActiveFormats,
		getShortcutKey: () => getShortcutKey,
		injectStyles: () => injectStyles,
		insertImage: () => insertImage,
		insertLink: () => insertLink,
		isFormatActive: () => isFormatActive,
		isValidUrl: () => isValidUrl,
		keyboardShortcuts: () => keyboardShortcuts,
		pluginManager: () => pluginManager,
		sanitizeHtml: () => sanitizeHtml,
		tablePlugin: () => tablePlugin,
		throttle: () => throttle,
		wordCountPlugin: () => wordCountPlugin,
	});

	// dist/core/utils/icons.js
	var icons = {
		bold: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4h8a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/><path d="M6 12h9a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/></svg>`,
		italic: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="4" x2="10" y2="4"/><line x1="14" y1="20" x2="5" y2="20"/><line x1="15" y1="4" x2="9" y2="20"/></svg>`,
		underline: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v6a6 6 0 0 0 12 0V4"/><line x1="4" y1="20" x2="20" y2="20"/></svg>`,
		strikethrough: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4H9a3 3 0 0 0-2.83 4"/><path d="M14 12a4 4 0 0 1 0 8H6"/><line x1="4" y1="12" x2="20" y2="12"/></svg>`,
		heading1: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h8"/><path d="M4 18V6"/><path d="M12 18V6"/><path d="m17 12 3-2v8"/></svg>`,
		heading2: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h8"/><path d="M4 18V6"/><path d="M12 18V6"/><path d="M21 18h-4c0-4 4-3 4-6 0-1.5-2-2.5-4-1"/></svg>`,
		heading3: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h8"/><path d="M4 18V6"/><path d="M12 18V6"/><path d="M17.5 10.5c1.7-1 3.5 0 3.5 1.5a2 2 0 0 1-2 2"/><path d="M17 17.5c2 1.5 4 .3 4-1.5a2 2 0 0 0-2-2"/></svg>`,
		paragraph: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 4v16"/><path d="M17 4v16"/><path d="M19 4H9.5a4.5 4.5 0 0 0 0 9H13"/></svg>`,
		bulletList: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>`,
		orderedList: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg>`,
		blockquote: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V21z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2h.75c0 2.25.25 4-2.75 4v3z"/></svg>`,
		codeBlock: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>`,
		link: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>`,
		image: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>`,
		smile: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>`,
		table: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/></svg>`,
		fileText: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>`,
	};

	// dist/core/plugins/emojiPicker.js
	var EMOJI_CATEGORIES = {
		smileys: {
			name: 'Smileys & People',
			emojis: [
				'\u{1F600}',
				'\u{1F603}',
				'\u{1F604}',
				'\u{1F601}',
				'\u{1F606}',
				'\u{1F605}',
				'\u{1F602}',
				'\u{1F923}',
				'\u{1F60A}',
				'\u{1F607}',
				'\u{1F642}',
				'\u{1F643}',
				'\u{1F609}',
				'\u{1F60C}',
				'\u{1F60D}',
				'\u{1F970}',
				'\u{1F618}',
				'\u{1F617}',
				'\u{1F619}',
				'\u{1F61A}',
				'\u{1F60B}',
				'\u{1F61B}',
				'\u{1F61D}',
				'\u{1F61C}',
				'\u{1F92A}',
				'\u{1F928}',
				'\u{1F9D0}',
				'\u{1F913}',
				'\u{1F60E}',
				'\u{1F929}',
				'\u{1F973}',
				'\u{1F60F}',
				'\u{1F612}',
				'\u{1F61E}',
				'\u{1F614}',
				'\u{1F61F}',
				'\u{1F615}',
				'\u{1F641}',
				'\u2639\uFE0F',
				'\u{1F623}',
				'\u{1F616}',
				'\u{1F62B}',
				'\u{1F629}',
				'\u{1F97A}',
				'\u{1F622}',
				'\u{1F62D}',
				'\u{1F624}',
				'\u{1F620}',
				'\u{1F621}',
			],
		},
		nature: {
			name: 'Animals & Nature',
			emojis: [
				'\u{1F436}',
				'\u{1F431}',
				'\u{1F42D}',
				'\u{1F439}',
				'\u{1F430}',
				'\u{1F98A}',
				'\u{1F43B}',
				'\u{1F43C}',
				'\u{1F428}',
				'\u{1F42F}',
				'\u{1F981}',
				'\u{1F42E}',
				'\u{1F437}',
				'\u{1F43D}',
				'\u{1F438}',
				'\u{1F435}',
				'\u{1F648}',
				'\u{1F649}',
				'\u{1F64A}',
				'\u{1F412}',
				'\u{1F414}',
				'\u{1F427}',
				'\u{1F426}',
				'\u{1F424}',
				'\u{1F423}',
				'\u{1F425}',
				'\u{1F986}',
				'\u{1F985}',
				'\u{1F989}',
				'\u{1F987}',
				'\u{1F43A}',
				'\u{1F417}',
				'\u{1F434}',
				'\u{1F984}',
				'\u{1F41D}',
				'\u{1F41B}',
				'\u{1F98B}',
				'\u{1F40C}',
				'\u{1F41E}',
				'\u{1F41C}',
			],
		},
		food: {
			name: 'Food & Drink',
			emojis: [
				'\u{1F34E}',
				'\u{1F350}',
				'\u{1F34A}',
				'\u{1F34B}',
				'\u{1F34C}',
				'\u{1F349}',
				'\u{1F347}',
				'\u{1F353}',
				'\u{1FAD0}',
				'\u{1F348}',
				'\u{1F352}',
				'\u{1F351}',
				'\u{1F96D}',
				'\u{1F34D}',
				'\u{1F965}',
				'\u{1F95D}',
				'\u{1F345}',
				'\u{1F346}',
				'\u{1F951}',
				'\u{1F966}',
				'\u{1F96C}',
				'\u{1F952}',
				'\u{1F336}\uFE0F',
				'\u{1FAD1}',
				'\u{1F33D}',
				'\u{1F955}',
				'\u{1FAD2}',
				'\u{1F9C4}',
				'\u{1F9C5}',
				'\u{1F954}',
				'\u{1F360}',
				'\u{1F950}',
				'\u{1F96F}',
				'\u{1F35E}',
				'\u{1F956}',
				'\u{1F968}',
				'\u{1F9C0}',
				'\u{1F95A}',
				'\u{1F373}',
				'\u{1F9C8}',
			],
		},
		objects: {
			name: 'Objects',
			emojis: [
				'\u231A',
				'\u{1F4F1}',
				'\u{1F4F2}',
				'\u{1F4BB}',
				'\u2328\uFE0F',
				'\u{1F5A5}\uFE0F',
				'\u{1F5A8}\uFE0F',
				'\u{1F5B1}\uFE0F',
				'\u{1F4BD}',
				'\u{1F4BE}',
				'\u{1F4BF}',
				'\u{1F4C0}',
				'\u{1F4FC}',
				'\u{1F4F7}',
				'\u{1F4F8}',
				'\u{1F4F9}',
				'\u{1F3A5}',
				'\u{1F4FD}\uFE0F',
				'\u{1F39E}\uFE0F',
				'\u{1F4DE}',
				'\u260E\uFE0F',
				'\u{1F4DF}',
				'\u{1F4E0}',
				'\u{1F4FA}',
				'\u{1F4FB}',
				'\u{1F399}\uFE0F',
				'\u{1F39A}\uFE0F',
				'\u{1F39B}\uFE0F',
				'\u23F1\uFE0F',
				'\u23F2\uFE0F',
				'\u23F0',
				'\u{1F570}\uFE0F',
				'\u231B',
				'\u23F3',
				'\u{1F4E1}',
				'\u{1F50B}',
				'\u{1F50C}',
				'\u{1F4A1}',
				'\u{1F526}',
				'\u{1F56F}\uFE0F',
			],
		},
		symbols: {
			name: 'Symbols',
			emojis: [
				'\u2764\uFE0F',
				'\u{1F9E1}',
				'\u{1F49B}',
				'\u{1F49A}',
				'\u{1F499}',
				'\u{1F49C}',
				'\u{1F5A4}',
				'\u{1F90D}',
				'\u{1F90E}',
				'\u{1F494}',
				'\u2763\uFE0F',
				'\u{1F495}',
				'\u{1F49E}',
				'\u{1F493}',
				'\u{1F497}',
				'\u{1F496}',
				'\u{1F498}',
				'\u{1F49D}',
				'\u2728',
				'\u2B50',
				'\u{1F31F}',
				'\u{1F4AB}',
				'\u26A1',
				'\u{1F525}',
				'\u{1F4A5}',
				'\u2744\uFE0F',
				'\u{1F308}',
				'\u2600\uFE0F',
				'\u{1F324}\uFE0F',
				'\u26C5',
				'\u{1F325}\uFE0F',
				'\u2601\uFE0F',
				'\u{1F326}\uFE0F',
				'\u{1F327}\uFE0F',
				'\u26C8\uFE0F',
				'\u{1F329}\uFE0F',
				'\u{1F328}\uFE0F',
				'\u2757',
				'\u2753',
				'\u{1F4AF}',
			],
		},
	};
	var emojiPlugin = {
		id: 'emoji-picker',
		name: 'Emoji Picker',
		version: '0.5.0',
		description: 'Add emojis to your content with an easy-to-use picker',
		author: 'Lilac Editor',
		toolbarButtons: [
			{
				id: 'emoji-picker',
				icon: icons.smile,
				label: 'Insert Emoji',
				tooltip: 'Insert emoji (Ctrl+Shift+E)',
				onClick: (context) => {
					const modal = document.createElement('div');
					modal.className = 'lilac-emoji-modal';
					modal.innerHTML = `
          <div class="lilac-emoji-modal-backdrop">
            <div class="lilac-emoji-modal-content">
              <div class="lilac-emoji-modal-header">
                <h3>Insert Emoji</h3>
                <button class="lilac-emoji-modal-close">&times;</button>
              </div>
              <div class="lilac-emoji-picker">
                <div class="lilac-emoji-categories">
                  ${Object.entries(EMOJI_CATEGORIES)
						.map(
							([
								key,
								category,
							]) => `<button class="lilac-emoji-category ${key === 'smileys' ? 'active' : ''}" data-category="${key}" title="${category.name}">
                      ${category.emojis[0]}
                    </button>`,
						)
						.join('')}
                </div>
                <div class="lilac-emoji-grid" id="emoji-grid">
                  ${EMOJI_CATEGORIES.smileys.emojis.map((emoji) => `<button class="lilac-emoji-button" data-emoji="${emoji}" title="${emoji}">${emoji}</button>`).join('')}
                </div>
              </div>
            </div>
          </div>
        `;
					document.body.appendChild(modal);
					const closeBtn = modal.querySelector('.lilac-emoji-modal-close');
					const backdrop = modal.querySelector('.lilac-emoji-modal-backdrop');
					const closeModal = () => modal.remove();
					closeBtn?.addEventListener('click', closeModal);
					backdrop?.addEventListener('click', (e) => {
						if (e.target === backdrop) closeModal();
					});
					modal.querySelectorAll('.lilac-emoji-category').forEach((btn) => {
						btn.addEventListener('click', () => {
							const category = btn.dataset.category;
							modal
								.querySelectorAll('.lilac-emoji-category')
								.forEach((b) => b.classList.remove('active'));
							btn.classList.add('active');
							const grid = modal.querySelector('#emoji-grid');
							if (grid) {
								grid.innerHTML = EMOJI_CATEGORIES[category].emojis
									.map(
										(emoji) =>
											`<button class="lilac-emoji-button" data-emoji="${emoji}" title="${emoji}">${emoji}</button>`,
									)
									.join('');
								grid.querySelectorAll('.lilac-emoji-button').forEach((emojiBtn) => {
									emojiBtn.addEventListener('click', () => {
										const emoji = emojiBtn.dataset.emoji;
										if (emoji) {
											context.insertContent(emoji);
											closeModal();
										}
									});
								});
							}
						});
					});
					modal.querySelectorAll('.lilac-emoji-button').forEach((btn) => {
						btn.addEventListener('click', () => {
							const emoji = btn.dataset.emoji;
							if (emoji) {
								context.insertContent(emoji);
								closeModal();
							}
						});
					});
				},
			},
		],
		keyboardShortcuts: [
			{
				key: 'e',
				ctrlKey: true,
				shiftKey: true,
				action: () => {
					const emojiButton = document.querySelector('[data-tooltip="Insert emoji (Ctrl+Shift+E)"]');
					if (emojiButton) {
						emojiButton.click();
					}
				},
			},
		],
		styles: `
    .lilac-emoji-modal {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      z-index: 1000;
    }
    .lilac-emoji-modal-backdrop {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .lilac-emoji-modal-content {
      background: var(--lilac-color-surface, #f8f9fb);
      border-radius: 8px;
      width: 400px;
      max-width: 90vw;
      max-height: 80vh;
      max-height: 80dvb;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
      overflow: hidden;
    }
    .lilac-emoji-modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 16px;
      border-bottom: 1px solid var(--lilac-color-border, #e1e5e9);
    }
    .lilac-emoji-modal-header h3 {
      margin: 0;
      font-size: 16px;
      font-weight: 600;
    }
    .lilac-emoji-modal-close {
      background: none;
      border: none;
      font-size: 24px;
      cursor: pointer;
      color: var(--lilac-color-text-secondary, #64748b);
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 4px;
    }
    .lilac-emoji-modal-close:hover {
      background: var(--lilac-color-hover, rgba(0,0,0,0.05));
    }
    .lilac-emoji-picker {
      padding: 16px;
    }
    .lilac-emoji-categories {
      display: flex;
      gap: 4px;
      margin-bottom: 16px;
      padding-bottom: 16px;
      border-bottom: 1px solid var(--lilac-color-border, #e1e5e9);
    }
    .lilac-emoji-category {
      background: none;
      border: none;
      padding: 8px;
      border-radius: 6px;
      cursor: pointer;
      font-size: 18px;
      line-height: 1;
      opacity: 0.6;
      transition: all 0.2s ease;
    }
    .lilac-emoji-category:hover {
      background: var(--lilac-color-hover, rgba(0,0,0,0.05));
      opacity: 1;
    }
    .lilac-emoji-category.active {
      background: var(--lilac-color-primary, #8b7cd8);
      opacity: 1;
    }
    .lilac-emoji-grid {
      display: grid;
      grid-template-columns: repeat(8, 1fr);
      gap: 4px;
      max-height: 300px;
      overflow-y: auto;
    }
    .lilac-emoji-button {
      background: none;
      border: none;
      padding: 8px;
      border-radius: 4px;
      cursor: pointer;
      font-size: 20px;
      line-height: 1;
      transition: background-color 0.2s ease;
      aspect-ratio: 1;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .lilac-emoji-button:hover {
      background: var(--lilac-color-hover, rgba(0,0,0,0.05));
    }
    .lilac-emoji-button:active {
      transform: scale(0.95);
    }
  `,
		onInstall: () => {
			console.log('Emoji Picker plugin installed');
		},
		onUninstall: () => {
			console.log('Emoji Picker plugin uninstalled');
		},
	};

	// dist/core/plugins/PluginManager.js
	var PluginManager = class {
		constructor() {
			this.plugins = /* @__PURE__ */ new Map();
			this.context = null;
			this.plugins = /* @__PURE__ */ new Map();
		}
		setContext(context) {
			this.context = context;
		}
		install(plugin) {
			if (this.plugins.has(plugin.id)) {
				console.warn(`Plugin ${plugin.id} is already installed`);
				return;
			}
			this.plugins.set(plugin.id, plugin);
			if (plugin.styles) {
				this.injectStyles(plugin.id, plugin.styles);
			}
			if (plugin.onInstall && this.context) {
				plugin.onInstall(this.context);
			}
			console.log(`Plugin ${plugin.id} (${plugin.name}) installed successfully`);
		}
		uninstall(pluginId) {
			const plugin = this.plugins.get(pluginId);
			if (!plugin) {
				console.warn(`Plugin ${pluginId} is not installed`);
				return;
			}
			if (plugin.onUninstall && this.context) {
				plugin.onUninstall(this.context);
			}
			this.removeStyles(pluginId);
			this.plugins.delete(pluginId);
			console.log(`Plugin ${pluginId} uninstalled successfully`);
		}
		getPlugin(pluginId) {
			return this.plugins.get(pluginId);
		}
		getAllPlugins() {
			return Array.from(this.plugins.values());
		}
		isInstalled(pluginId) {
			return this.plugins.has(pluginId);
		}
		executeHook(hook, ...args) {
			if (!this.context) return;
			this.plugins.forEach((plugin) => {
				const hookFn = plugin[hook];
				if (typeof hookFn === 'function') {
					try {
						hookFn.apply(plugin, [this.context, ...args]);
					} catch (error) {
						console.error(`Error executing hook ${String(hook)} for plugin ${plugin.id}:`, error);
					}
				}
			});
		}
		// Get all toolbar buttons from plugins
		getToolbarButtons() {
			const buttons = [];
			this.plugins.forEach((plugin) => {
				if (plugin.toolbarButtons) {
					buttons.push(...plugin.toolbarButtons);
				}
			});
			return buttons;
		}
		// Get all context menu items from plugins
		getContextMenuItems() {
			const items = [];
			this.plugins.forEach((plugin) => {
				if (plugin.contextMenuItems) {
					items.push(...plugin.contextMenuItems);
				}
			});
			return items;
		}
		// Get all keyboard shortcuts from plugins
		getKeyboardShortcuts() {
			const shortcuts = [];
			this.plugins.forEach((plugin) => {
				if (plugin.keyboardShortcuts) {
					shortcuts.push(...plugin.keyboardShortcuts);
				}
			});
			return shortcuts;
		}
		// Get all panels from plugins
		getPanels() {
			const panels = [];
			this.plugins.forEach((plugin) => {
				if (plugin.panels) {
					panels.push(...plugin.panels);
				}
			});
			return panels;
		}
		// Transform content using all installed plugins
		transformContent(content) {
			let transformedContent = content;
			this.plugins.forEach((plugin) => {
				if (plugin.contentTransformers && this.context) {
					plugin.contentTransformers.forEach((transformer) => {
						transformedContent = transformer.transform(transformedContent, this.context);
					});
				}
			});
			return transformedContent;
		}
		injectStyles(pluginId, styles) {
			const styleId = `lilac-plugin-${pluginId}`;
			const existingStyle = document.getElementById(styleId);
			if (existingStyle) {
				existingStyle.remove();
			}
			const styleElement = document.createElement('style');
			styleElement.id = styleId;
			styleElement.textContent = styles;
			document.head.appendChild(styleElement);
		}
		removeStyles(pluginId) {
			const styleId = `lilac-plugin-${pluginId}`;
			const styleElement = document.getElementById(styleId);
			if (styleElement) {
				styleElement.remove();
			}
		}
	};
	var pluginManager = new PluginManager();

	// dist/core/plugins/tableInserter.js
	var tablePlugin = {
		id: 'table-inserter',
		name: 'Table Inserter',
		version: '0.5.0',
		description: 'Insert and manage HTML tables',
		author: 'Lilac Editor',
		toolbarButtons: [
			{
				id: 'insert-table',
				icon: icons.table,
				label: 'Insert Table',
				tooltip: 'Insert table (Ctrl+Shift+T)',
				onClick: (context) => {
					const modal = document.createElement('div');
					modal.className = 'lilac-table-modal';
					modal.innerHTML = `
          <div class="lilac-table-modal-backdrop">
            <div class="lilac-table-modal-content">
              <div class="lilac-table-modal-header">
                <h3>Insert Table</h3>
                <button class="lilac-table-modal-close">&times;</button>
              </div>
              <div class="lilac-table-options">
                <div class="lilac-table-option">
                  <label for="table-rows">Rows:</label>
                  <input type="number" id="table-rows" min="1" max="20" value="3">
                </div>
                <div class="lilac-table-option">
                  <label for="table-cols">Columns:</label>
                  <input type="number" id="table-cols" min="1" max="10" value="3">
                </div>
                <div class="lilac-table-option">
                  <label>
                    <input type="checkbox" id="table-header" checked>
                    Include header row
                  </label>
                </div>
                <div class="lilac-table-option">
                  <label>
                    <input type="checkbox" id="table-borders" checked>
                    Show borders
                  </label>
                </div>
              </div>
              <div class="lilac-table-preview">
                <h4>Preview:</h4>
                <div id="table-preview-container"></div>
              </div>
              <div class="lilac-table-actions">
                <button class="lilac-btn lilac-btn-secondary" id="table-cancel">Cancel</button>
                <button class="lilac-btn lilac-btn-primary" id="table-insert">Insert Table</button>
              </div>
            </div>
          </div>
        `;
					document.body.appendChild(modal);
					const rowsInput = modal.querySelector('#table-rows');
					const colsInput = modal.querySelector('#table-cols');
					const headerCheck = modal.querySelector('#table-header');
					const bordersCheck = modal.querySelector('#table-borders');
					const previewContainer = modal.querySelector('#table-preview-container');
					const closeBtn = modal.querySelector('.lilac-table-modal-close');
					const cancelBtn = modal.querySelector('#table-cancel');
					const insertBtn = modal.querySelector('#table-insert');
					const backdrop = modal.querySelector('.lilac-table-modal-backdrop');
					const generateTableHTML = () => {
						const rows = parseInt(rowsInput.value) || 3;
						const cols = parseInt(colsInput.value) || 3;
						const hasHeader = headerCheck.checked;
						const hasBorders = bordersCheck.checked;
						let html = `<div class="lilac-table-wrapper" contenteditable="false">
            <table class="lilac-table${hasBorders ? ' lilac-table-bordered' : ''}" contenteditable="true">`;
						if (hasHeader) {
							html += '<thead><tr>';
							for (let c = 0; c < cols; c++) {
								html += `<th contenteditable="true">Header ${c + 1}</th>`;
							}
							html += '</tr></thead>';
						}
						html += '<tbody>';
						const startRow = hasHeader ? 1 : 0;
						const totalRows = rows;
						for (let r = startRow; r < totalRows + startRow; r++) {
							html += '<tr>';
							for (let c = 0; c < cols; c++) {
								html += `<td contenteditable="true">Cell ${r + 1}-${c + 1}</td>`;
							}
							html += '</tr>';
						}
						html += '</tbody></table>';
						html += `
            <div class="lilac-table-toolbar">
              <button class="lilac-table-btn" data-action="add-row" title="Add row below">+ Row</button>
              <button class="lilac-table-btn" data-action="add-col" title="Add column right">+ Column</button>
              <button class="lilac-table-btn" data-action="delete-row" title="Delete last row">\u2212 Row</button>
              <button class="lilac-table-btn" data-action="delete-col" title="Delete last column">\u2212 Column</button>
              <button class="lilac-table-btn lilac-table-btn-danger" data-action="delete-table" title="Delete table">Delete Table</button>
            </div>
          </div>`;
						return html;
					};
					const attachTableControls = (wrapper) => {
						const table = wrapper.querySelector('.lilac-table');
						if (!table) return;
						const toolbar = wrapper.querySelector('.lilac-table-toolbar');
						if (!toolbar) return;
						toolbar.querySelectorAll('.lilac-table-btn').forEach((btn) => {
							btn.addEventListener('click', (e) => {
								e.preventDefault();
								e.stopPropagation();
								const action = btn.dataset.action;
								switch (action) {
									case 'add-row': {
										const newRow = document.createElement('tr');
										const colCount = table.rows[0]?.cells.length || 3;
										for (let i = 0; i < colCount; i++) {
											const cell = document.createElement('td');
											cell.contentEditable = 'true';
											cell.textContent = 'New cell';
											newRow.appendChild(cell);
										}
										const tbody = table.querySelector('tbody');
										if (tbody) tbody.appendChild(newRow);
										break;
									}
									case 'add-col': {
										table.querySelectorAll('tr').forEach((row, idx) => {
											const cell = document.createElement(
												idx === 0 && table.querySelector('thead') ? 'th' : 'td',
											);
											cell.contentEditable = 'true';
											cell.textContent = 'New';
											row.appendChild(cell);
										});
										break;
									}
									case 'delete-row': {
										const tbody = table.querySelector('tbody');
										if (tbody && tbody.rows.length > 1) {
											tbody.deleteRow(-1);
										}
										break;
									}
									case 'delete-col': {
										const colCount = table.rows[0]?.cells.length || 0;
										if (colCount > 1) {
											table.querySelectorAll('tr').forEach((row) => {
												if (row.cells.length > 0) {
													row.deleteCell(-1);
												}
											});
										}
										break;
									}
									case 'delete-table': {
										if (confirm('Delete this table?')) {
											wrapper.remove();
										}
										break;
									}
								}
							});
						});
					};
					const updatePreview = () => {
						previewContainer.innerHTML = generateTableHTML();
						const wrapper = previewContainer.querySelector('.lilac-table-wrapper');
						if (wrapper) {
							attachTableControls(wrapper);
						}
					};
					const closeModal = () => modal.remove();
					[rowsInput, colsInput, headerCheck, bordersCheck].forEach((input) => {
						input.addEventListener('change', updatePreview);
						input.addEventListener('input', updatePreview);
					});
					closeBtn.addEventListener('click', closeModal);
					cancelBtn.addEventListener('click', closeModal);
					backdrop.addEventListener('click', (e) => {
						if (e.target === backdrop) closeModal();
					});
					insertBtn.addEventListener('click', () => {
						const tableHTML = generateTableHTML();
						context.insertContent(tableHTML);
						closeModal();
						setTimeout(() => {
							const wrappers = context.element?.querySelectorAll('.lilac-table-wrapper');
							if (wrappers) {
								wrappers.forEach((wrapper) => {
									attachTableControls(wrapper);
								});
							}
						}, 100);
					});
					updatePreview();
				},
			},
		],
		keyboardShortcuts: [
			{
				key: 't',
				ctrlKey: true,
				shiftKey: true,
				action: () => {
					const tableButton = document.querySelector('[data-tooltip="Insert table (Ctrl+Shift+T)"]');
					if (tableButton) {
						tableButton.click();
					}
				},
			},
		],
		styles: `
    .lilac-table-modal {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      z-index: 1000;
    }
    .lilac-table-modal-backdrop {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .lilac-table-modal-content {
      background: var(--lilac-color-surface, #f8f9fb);
      border-radius: 8px;
      width: 500px;
      max-width: 90vw;
      max-height: 80vh;
      max-height: 80dvb;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
      overflow: hidden;
    }
    .lilac-table-modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 16px;
      border-bottom: 1px solid var(--lilac-color-border, #e1e5e9);
    }
    .lilac-table-modal-header h3 {
      margin: 0;
      font-size: 16px;
      font-weight: 600;
    }
    .lilac-table-modal-close {
      background: none;
      border: none;
      font-size: 24px;
      cursor: pointer;
      color: var(--lilac-color-text-secondary, #64748b);
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 4px;
    }
    .lilac-table-modal-close:hover {
      background: var(--lilac-color-hover, rgba(0,0,0,0.05));
    }
    .lilac-table-options {
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .lilac-table-option {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .lilac-table-option label {
      font-weight: 500;
      min-width: 80px;
    }
    .lilac-table-option input[type="number"] {
      padding: 4px 8px;
      border: 1px solid var(--lilac-color-border, #e1e5e9);
      border-radius: 4px;
      width: 60px;
    }
    .lilac-table-option input[type="checkbox"] {
      margin-right: 4px;
    }
    .lilac-table-preview {
      padding: 16px;
      border-top: 1px solid var(--lilac-color-border, #e1e5e9);
      border-bottom: 1px solid var(--lilac-color-border, #e1e5e9);
      background: var(--lilac-color-background, #fff);
      overflow-x: auto;
    }
    .lilac-table-preview h4 {
      margin: 0 0 12px 0;
      font-size: 14px;
      font-weight: 600;
    }
    .lilac-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 14px;
    }
    .lilac-table th,
    .lilac-table td {
      padding: 8px 12px;
      text-align: left;
    }
    .lilac-table th {
      font-weight: 600;
      background: var(--lilac-color-surface, #f8f9fb);
    }
    .lilac-table-bordered {
      border: 1px solid var(--lilac-color-border, #e1e5e9);
    }
    .lilac-table-bordered th,
    .lilac-table-bordered td {
      border: 1px solid var(--lilac-color-border, #e1e5e9);
    }
    .lilac-table-actions {
      padding: 16px;
      display: flex;
      justify-content: flex-end;
      gap: 8px;
    }
    .lilac-btn {
      padding: 8px 16px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-weight: 500;
      transition: all 0.2s ease;
    }
    .lilac-btn-primary {
      background: var(--lilac-color-primary, #8b7cd8);
      color: white;
    }
    .lilac-btn-primary:hover {
      background: var(--lilac-color-primary-dark, #6d5ac8);
    }
    .lilac-btn-secondary {
      background: var(--lilac-color-surface, #f8f9fb);
      color: var(--lilac-color-text, #090e12);
      border: 1px solid var(--lilac-color-border, #e1e5e9);
    }
    .lilac-btn-secondary:hover {
      background: var(--lilac-color-hover, rgba(0,0,0,0.05));
    }
    .lilac-table-wrapper {
      position: relative;
      margin: 16px 0;
      display: inline-block;
      max-width: 100%;
    }
    .lilac-table-toolbar {
      display: flex;
      gap: 4px;
      padding: 8px;
      background: var(--lilac-color-surface, #f8f9fb);
      border: 1px solid var(--lilac-color-border, #e1e5e9);
      border-top: none;
      border-radius: 0 0 4px 4px;
    }
    .lilac-table-btn {
      padding: 4px 8px;
      font-size: 12px;
      border: 1px solid var(--lilac-color-border, #e1e5e9);
      background: var(--lilac-color-background, #fff);
      color: var(--lilac-color-text, #090e12);
      border-radius: 4px;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .lilac-table-btn:hover {
      background: var(--lilac-color-primary, #8b7cd8);
      color: white;
      border-color: var(--lilac-color-primary, #8b7cd8);
    }
    .lilac-table-btn-danger:hover {
      background: #dc3545;
      border-color: #dc3545;
      color: white;
    }
    .lilac-table-wrapper table {
      border-radius: 4px 4px 0 0;
    }
  `,
		onInstall: () => {
			console.log('Table Inserter plugin installed');
		},
		onUninstall: () => {
			console.log('Table Inserter plugin uninstalled');
		},
	};

	// dist/core/plugins/wordCount.js
	function calculateStats(content) {
		const text = content.replace(/<[^>]*>/g, '');
		return {
			words: text.trim() ? text.trim().split(/\s+/).length : 0,
			characters: text.length,
			charactersNoSpaces: text.replace(/\s/g, '').length,
			paragraphs: text.trim() ? text.split(/\n\s*\n/).length : 0,
		};
	}
	function createWordCountPanel(context) {
		const panel = document.createElement('div');
		panel.className = 'lilac-word-count-panel';
		panel.innerHTML = `
    <h3 style="margin: 0 0 16px 0; font-size: 14px; font-weight: 600;">Document Statistics</h3>
    <div class="lilac-word-count-stats">
      <div class="lilac-word-count-stat">
        <span class="label">Words:</span>
        <span class="value" id="wc-words">0</span>
      </div>
      <div class="lilac-word-count-stat">
        <span class="label">Characters:</span>
        <span class="value" id="wc-chars">0</span>
      </div>
      <div class="lilac-word-count-stat">
        <span class="label">Characters (no spaces):</span>
        <span class="value" id="wc-chars-no-space">0</span>
      </div>
      <div class="lilac-word-count-stat">
        <span class="label">Paragraphs:</span>
        <span class="value" id="wc-paragraphs">0</span>
      </div>
    </div>
  `;
		const updateStats = () => {
			const stats = calculateStats(context.state.content);
			const wordsEl = panel.querySelector('#wc-words');
			const charsEl = panel.querySelector('#wc-chars');
			const charsNoSpaceEl = panel.querySelector('#wc-chars-no-space');
			const paragraphsEl = panel.querySelector('#wc-paragraphs');
			if (wordsEl) wordsEl.textContent = stats.words.toLocaleString();
			if (charsEl) charsEl.textContent = stats.characters.toLocaleString();
			if (charsNoSpaceEl) charsNoSpaceEl.textContent = stats.charactersNoSpaces.toLocaleString();
			if (paragraphsEl) paragraphsEl.textContent = stats.paragraphs.toLocaleString();
		};
		updateStats();
		return panel;
	}
	var wordCountPlugin = {
		id: 'word-count',
		name: 'Word Count',
		version: '0.5.0',
		description: 'Displays word count and document statistics',
		author: 'Lilac Editor',
		panels: [
			{
				id: 'word-count-panel',
				title: 'Word Count',
				icon: icons.fileText,
				position: 'right',
				defaultOpen: false,
				render: (context, container) => {
					const panel = createWordCountPanel(context);
					container.appendChild(panel);
					const updateStats = () => {
						const stats = calculateStats(context.state.content);
						const wordsEl = panel.querySelector('#wc-words');
						const charsEl = panel.querySelector('#wc-chars');
						const charsNoSpaceEl = panel.querySelector('#wc-chars-no-space');
						const paragraphsEl = panel.querySelector('#wc-paragraphs');
						if (wordsEl) wordsEl.textContent = stats.words.toLocaleString();
						if (charsEl) charsEl.textContent = stats.characters.toLocaleString();
						if (charsNoSpaceEl) charsNoSpaceEl.textContent = stats.charactersNoSpaces.toLocaleString();
						if (paragraphsEl) paragraphsEl.textContent = stats.paragraphs.toLocaleString();
					};
					updateStats();
					const interval = setInterval(() => {
						updateStats();
					}, 500);
					panel._updateInterval = interval;
				},
				destroy: () => {
					const panel = document.querySelector('.lilac-word-count-panel');
					if (panel && panel._updateInterval) {
						clearInterval(panel._updateInterval);
					}
				},
			},
		],
		toolbarButtons: [
			{
				id: 'word-count-toggle',
				icon: icons.fileText,
				label: 'Word Count',
				tooltip: 'Show word count',
				onClick: (context) => {
					let modal = document.querySelector('.lilac-word-count-modal');
					if (modal) {
						modal.remove();
						return;
					}
					modal = document.createElement('div');
					modal.className = 'lilac-word-count-modal';
					modal.innerHTML = `
          <div class="lilac-word-count-modal-backdrop">
            <div class="lilac-word-count-modal-content">
              <div class="lilac-word-count-modal-header">
                <h3>Document Statistics</h3>
                <button class="lilac-word-count-modal-close">&times;</button>
              </div>
              <div id="word-count-panel-container"></div>
            </div>
          </div>
        `;
					document.body.appendChild(modal);
					const container = modal.querySelector('#word-count-panel-container');
					if (container) {
						container.appendChild(createWordCountPanel(context));
					}
					const closeBtn = modal.querySelector('.lilac-word-count-modal-close');
					const backdrop = modal.querySelector('.lilac-word-count-modal-backdrop');
					const closeModal = () => modal.remove();
					closeBtn?.addEventListener('click', closeModal);
					backdrop?.addEventListener('click', (e) => {
						if (e.target === backdrop) closeModal();
					});
				},
			},
		],
		styles: `
    .lilac-word-count-modal {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      z-index: 1000;
    }
    .lilac-word-count-modal-backdrop {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .lilac-word-count-modal-content {
      background: var(--lilac-color-surface, #f8f9fb);
      border-radius: 8px;
      width: 320px;
      max-width: 90vw;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    }
    .lilac-word-count-modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 16px;
      border-bottom: 1px solid var(--lilac-color-border, #e1e5e9);
    }
    .lilac-word-count-modal-header h3 {
      margin: 0;
      font-size: 16px;
      font-weight: 600;
    }
    .lilac-word-count-modal-close {
      background: none;
      border: none;
      font-size: 24px;
      cursor: pointer;
      color: var(--lilac-color-text-secondary, #64748b);
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 4px;
    }
    .lilac-word-count-modal-close:hover {
      background: var(--lilac-color-hover, rgba(0,0,0,0.05));
    }
    .lilac-word-count-panel {
      padding: 16px;
      font-family: var(--lilac-font-family, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif);
      font-size: 13px;
    }
    .lilac-word-count-stats {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .lilac-word-count-stat {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 4px 0;
      border-bottom: 1px solid var(--lilac-color-border, #e1e5e9);
    }
    .lilac-word-count-stat:last-child {
      border-bottom: none;
    }
    .lilac-word-count-stat .label {
      color: var(--lilac-color-text-secondary, #64748b);
      font-weight: 500;
    }
    .lilac-word-count-stat .value {
      font-weight: 600;
      color: var(--lilac-color-primary, #8b7cd8);
      font-variant-numeric: tabular-nums;
    }
  `,
		onInstall: () => {
			console.log('Word Count plugin installed');
		},
		onUninstall: () => {
			console.log('Word Count plugin uninstalled');
		},
	};

	// dist/core/utils/formatting.js
	var formatCommands = {
		bold: { command: 'bold' },
		italic: { command: 'italic' },
		underline: { command: 'underline' },
		strikethrough: { command: 'strikeThrough' },
		heading1: { command: 'formatBlock', value: 'h1' },
		heading2: { command: 'formatBlock', value: 'h2' },
		heading3: { command: 'formatBlock', value: 'h3' },
		paragraph: { command: 'formatBlock', value: 'p' },
		bulletList: { command: 'insertUnorderedList' },
		orderedList: { command: 'insertOrderedList' },
		blockquote: { command: 'formatBlock', value: 'blockquote' },
		codeBlock: { command: 'formatBlock', value: 'pre' },
		link: null,
		// Custom implementation needed
		image: null,
		// Custom implementation needed
		separator: null,
		// Not a command
	};
	function executeFormatCommand(tool, value) {
		const formatCommand = formatCommands[tool];
		if (!formatCommand) {
			return false;
		}
		try {
			if (formatCommand.value !== void 0) {
				return document.execCommand(formatCommand.command, false, value || formatCommand.value);
			} else {
				return document.execCommand(formatCommand.command, false);
			}
		} catch (error) {
			console.warn(`Failed to execute format command for ${tool}:`, error);
			return false;
		}
	}
	function isFormatActive(tool) {
		const formatCommand = formatCommands[tool];
		if (!formatCommand) {
			return false;
		}
		try {
			if (formatCommand.command === 'formatBlock') {
				return document.queryCommandValue('formatBlock').toLowerCase() === formatCommand.value.toLowerCase();
			} else {
				return document.queryCommandState(formatCommand.command);
			}
		} catch (error) {
			console.warn(`Failed to query format state for ${tool}:`, error);
			return false;
		}
	}
	function getActiveFormats(tools) {
		const activeFormats = /* @__PURE__ */ new Set();
		for (const tool of tools) {
			if (tool !== 'separator' && isFormatActive(tool)) {
				activeFormats.add(tool);
			}
		}
		return activeFormats;
	}
	function insertLink(url, text) {
		const selection = window.getSelection();
		if (!selection || selection.rangeCount === 0) {
			return false;
		}
		try {
			const range = selection.getRangeAt(0);
			const selectedText = range.toString();
			const linkText = text || selectedText || url;
			if (selectedText) {
				const link = document.createElement('a');
				link.href = url;
				link.textContent = linkText;
				range.deleteContents();
				range.insertNode(link);
				selection.removeAllRanges();
				return true;
			} else {
				const link = document.createElement('a');
				link.href = url;
				link.textContent = linkText;
				range.insertNode(link);
				selection.removeAllRanges();
				return true;
			}
		} catch (error) {
			console.warn('Failed to insert link:', error);
			return false;
		}
	}
	function insertImage(src, alt) {
		try {
			const img = document.createElement('img');
			img.src = src;
			img.alt = alt || 'Image';
			img.style.maxWidth = '100%';
			img.style.height = 'auto';
			const selection = window.getSelection();
			if (selection && selection.rangeCount > 0) {
				const range = selection.getRangeAt(0);
				range.insertNode(img);
				range.setStartAfter(img);
				range.setEndAfter(img);
				selection.removeAllRanges();
				selection.addRange(range);
				return true;
			}
			return false;
		} catch (error) {
			console.warn('Failed to insert image:', error);
			return false;
		}
	}
	var keyboardShortcuts = {
		'ctrl+b': 'bold',
		'cmd+b': 'bold',
		'ctrl+i': 'italic',
		'cmd+i': 'italic',
		'ctrl+u': 'underline',
		'cmd+u': 'underline',
		'ctrl+k': 'link',
		'cmd+k': 'link',
	};
	function getShortcutKey(event) {
		const key = event.key.toLowerCase();
		const ctrl = event.ctrlKey || event.metaKey;
		const shift = event.shiftKey;
		const alt = event.altKey;
		if (!ctrl) return null;
		let shortcut = '';
		if (event.metaKey) shortcut += 'cmd+';
		if (event.ctrlKey) shortcut += 'ctrl+';
		if (alt) shortcut += 'alt+';
		if (shift) shortcut += 'shift+';
		shortcut += key;
		return shortcut;
	}
	function cn(...classes) {
		const result = [];
		for (const cls of classes) {
			if (!cls) continue;
			if (typeof cls === 'string') {
				result.push(cls);
			} else if (typeof cls === 'object') {
				for (const [key, value] of Object.entries(cls)) {
					if (value) result.push(key);
				}
			}
		}
		return result.join(' ');
	}
	function debounce(fn, delay) {
		let timeoutId;
		return (...args) => {
			clearTimeout(timeoutId);
			timeoutId = setTimeout(() => fn(...args), delay);
		};
	}
	function throttle(fn, limit) {
		let inThrottle;
		return (...args) => {
			if (!inThrottle) {
				fn(...args);
				inThrottle = true;
				setTimeout(() => (inThrottle = false), limit);
			}
		};
	}
	function isValidUrl(string) {
		try {
			new URL(string);
			return true;
		} catch (_) {
			return false;
		}
	}
	function sanitizeHtml(html) {
		const div = document.createElement('div');
		div.textContent = html;
		return div.innerHTML;
	}
	function extractTextFromHtml(html) {
		const div = document.createElement('div');
		div.innerHTML = html;
		return div.textContent || div.innerText || '';
	}

	// dist/core/components/Toolbar.js
	var toolIcons = {
		bold: icons.bold,
		italic: icons.italic,
		underline: icons.underline,
		strikethrough: icons.strikethrough,
		heading1: icons.heading1,
		heading2: icons.heading2,
		heading3: icons.heading3,
		paragraph: icons.paragraph,
		bulletList: icons.bulletList,
		orderedList: icons.orderedList,
		blockquote: icons.blockquote,
		codeBlock: icons.codeBlock,
		link: icons.link,
		image: icons.image,
		separator: '',
	};
	var toolLabels = {
		bold: 'Bold (Ctrl+B)',
		italic: 'Italic (Ctrl+I)',
		underline: 'Underline (Ctrl+U)',
		strikethrough: 'Strikethrough',
		heading1: 'Heading 1',
		heading2: 'Heading 2',
		heading3: 'Heading 3',
		paragraph: 'Paragraph',
		bulletList: 'Bullet List',
		orderedList: 'Numbered List',
		blockquote: 'Quote',
		codeBlock: 'Code Block',
		link: 'Link (Ctrl+K)',
		image: 'Image',
		separator: 'Separator',
	};
	var defaultTools = [
		'bold',
		'italic',
		'underline',
		'separator',
		'heading1',
		'heading2',
		'heading3',
		'paragraph',
		'separator',
		'bulletList',
		'orderedList',
		'blockquote',
		'separator',
		'link',
		'codeBlock',
	];
	var Toolbar = class {
		constructor(options) {
			this.options = {
				tools: defaultTools,
				activeTools: /* @__PURE__ */ new Set(),
				disabled: false,
				pluginButtons: [],
				...options,
			};
			this.element = this.createElement();
		}
		createElement() {
			const toolbar = document.createElement('div');
			toolbar.className = cn('lilac-toolbar', { 'lilac-toolbar--disabled': !!this.options.disabled });
			const tools = this.options.tools || defaultTools;
			tools.forEach((tool, index) => {
				if (tool === 'separator') {
					const separator = document.createElement('div');
					separator.className = 'lilac-toolbar__separator';
					separator.setAttribute('aria-hidden', 'true');
					toolbar.appendChild(separator);
				} else {
					const button = this.createButton(tool, index);
					toolbar.appendChild(button);
				}
			});
			if (this.options.pluginButtons && this.options.pluginButtons.length > 0) {
				const separator = document.createElement('div');
				separator.className = 'lilac-toolbar__separator';
				separator.setAttribute('aria-hidden', 'true');
				toolbar.appendChild(separator);
				this.options.pluginButtons.forEach((pluginBtn) => {
					const button = this.createPluginButton(pluginBtn);
					toolbar.appendChild(button);
				});
			}
			return toolbar;
		}
		createButton(tool, _index) {
			const button = document.createElement('button');
			const isActive = this.options.activeTools?.has(tool);
			button.type = 'button';
			button.className = cn('lilac-toolbar__button', {
				'lilac-toolbar__button--active': !!isActive,
				'lilac-toolbar__button--disabled': !!this.options.disabled,
			});
			button.innerHTML = toolIcons[tool];
			button.title = toolLabels[tool];
			button.setAttribute('aria-label', toolLabels[tool]);
			button.setAttribute('aria-pressed', String(isActive));
			button.disabled = this.options.disabled || false;
			button.dataset.tool = tool;
			button.addEventListener('click', () => {
				if (!this.options.disabled) {
					this.options.onToolClick(tool);
				}
			});
			return button;
		}
		createPluginButton(pluginBtn) {
			const button = document.createElement('button');
			const isActive = pluginBtn.isActive?.(this.options.editorContext);
			button.type = 'button';
			button.className = cn('lilac-toolbar__button', 'lilac-toolbar__button--plugin', {
				'lilac-toolbar__button--active': !!isActive,
				'lilac-toolbar__button--disabled': !!this.options.disabled,
			});
			button.innerHTML = pluginBtn.icon;
			button.title = pluginBtn.tooltip || pluginBtn.label;
			button.setAttribute('aria-label', pluginBtn.label);
			button.setAttribute('data-tooltip', pluginBtn.tooltip || pluginBtn.label);
			button.disabled = this.options.disabled || false;
			button.addEventListener('click', () => {
				if (!this.options.disabled && this.options.editorContext) {
					pluginBtn.onClick(this.options.editorContext);
				}
			});
			return button;
		}
		getElement() {
			return this.element;
		}
		updateActiveTools(activeTools) {
			this.options.activeTools = activeTools;
			this.element.querySelectorAll('.lilac-toolbar__button').forEach((btn) => {
				const tool = btn.dataset.tool;
				if (tool) {
					const isActive = activeTools.has(tool);
					btn.classList.toggle('lilac-toolbar__button--active', isActive);
					btn.setAttribute('aria-pressed', String(isActive));
				}
			});
		}
		setDisabled(disabled) {
			this.options.disabled = disabled;
			this.element.classList.toggle('lilac-toolbar--disabled', disabled);
			this.element.querySelectorAll('button').forEach((btn) => {
				btn.disabled = disabled;
				btn.classList.toggle('lilac-toolbar__button--disabled', disabled);
			});
		}
	};

	// dist/core/components/Editor.js
	var LilacEditor = class {
		constructor(props) {
			this.toolbar = null;
			this.lastContentRef = '';
			this.props = {
				initialContent: '',
				placeholder: 'Start writing...',
				readOnly: false,
				autoFocus: false,
				minHeight: 200,
				maxHeight: 600,
				theme: 'light',
				plugins: [],
				...props,
			};
			this.container = props.container;
			this.state = {
				content: this.props.initialContent || '',
				selection: null,
				history: {
					undoStack: [],
					redoStack: [],
					maxHistorySize: 50,
				},
				isReadOnly: this.props.readOnly || false,
			};
			this.editorWrapper = this.createEditor();
			this.contentElement = this.editorWrapper.querySelector('.lilac-editor__content');
			this.container.appendChild(this.editorWrapper);
			this.initializePlugins();
			this.setupEventListeners();
			if (this.props.autoFocus) {
				setTimeout(() => this.focus(), 0);
			}
		}
		createEditor() {
			const wrapper = document.createElement('div');
			wrapper.className = cn(
				'lilac-editor',
				`lilac-editor--${this.props.theme}`,
				{
					'lilac-editor--readonly': this.state.isReadOnly,
					'lilac-editor--empty': !this.state.content.trim(),
				},
				this.props.className,
			);
			if (this.props.toolbar?.show) {
				this.toolbar = new Toolbar({
					tools: this.props.toolbar.tools,
					onToolClick: (tool) => this.handleToolClick(tool),
					activeTools: /* @__PURE__ */ new Set(),
					disabled: this.state.isReadOnly,
					pluginButtons: pluginManager.getToolbarButtons(),
					editorContext: this.getEditorContext(),
				});
				wrapper.appendChild(this.toolbar.getElement());
			}
			const contentWrapper = document.createElement('div');
			contentWrapper.className = 'lilac-editor__content-wrapper';
			contentWrapper.style.minHeight = `${this.props.minHeight}px`;
			contentWrapper.style.maxHeight = `${this.props.maxHeight}px`;
			const placeholder = document.createElement('div');
			placeholder.className = 'lilac-editor__placeholder';
			placeholder.textContent = this.props.placeholder || 'Start writing...';
			placeholder.style.display = this.state.content.trim() ? 'none' : 'block';
			contentWrapper.appendChild(placeholder);
			const content = document.createElement('div');
			content.className = 'lilac-editor__content';
			content.contentEditable = (!this.state.isReadOnly).toString();
			content.setAttribute('role', 'textbox');
			content.setAttribute('aria-multiline', 'true');
			content.setAttribute('aria-label', 'Text editor');
			content.setAttribute('data-testid', 'lilac-editor-content');
			if (this.props.toolbar?.show) {
				content.innerHTML = this.props.initialContent || '';
			} else {
				content.textContent = this.props.initialContent || '';
			}
			this.lastContentRef = this.props.initialContent || '';
			contentWrapper.appendChild(content);
			wrapper.appendChild(contentWrapper);
			if (this.props.maxLength) {
				const footer = document.createElement('div');
				footer.className = 'lilac-editor__footer';
				footer.innerHTML = `
        <span class="lilac-editor__char-count">
          ${this.state.content.length}/${this.props.maxLength}
        </span>
      `;
				wrapper.appendChild(footer);
			}
			return wrapper;
		}
		getEditorContext() {
			return {
				state: this.state,
				setState: (newState) => {
					if (newState.content !== void 0) {
						this.updateContent(newState.content);
					}
				},
				element: this.contentElement,
				focus: () => this.focus(),
				blur: () => this.blur(),
				insertContent: (content) => {
					if (this.contentElement) {
						this.contentElement.focus();
						document.execCommand('insertHTML', false, content);
						setTimeout(() => this.updateContentFromDOM(), 0);
					}
				},
				formatSelection: (command, value) => {
					if (this.contentElement) {
						document.execCommand(command, false, value);
						setTimeout(() => this.updateContentFromDOM(), 0);
					}
				},
				getSelectedText: () => {
					const selection = window.getSelection();
					return selection ? selection.toString() : '';
				},
			};
		}
		initializePlugins() {
			const context = this.getEditorContext();
			pluginManager.setContext(context);
			this.props.plugins?.forEach((plugin) => {
				if (!pluginManager.isInstalled(plugin.id)) {
					pluginManager.install(plugin);
				}
			});
			if (this.toolbar) {
				const pluginButtons = pluginManager.getToolbarButtons();
				if (pluginButtons.length > 0) {
					const toolbarElement = this.toolbar.getElement();
					const newToolbar = new Toolbar({
						tools: this.props.toolbar?.tools,
						onToolClick: (tool) => this.handleToolClick(tool),
						activeTools: /* @__PURE__ */ new Set(),
						disabled: this.state.isReadOnly,
						pluginButtons,
						editorContext: this.getEditorContext(),
					});
					toolbarElement.replaceWith(newToolbar.getElement());
					this.toolbar = newToolbar;
				}
			}
			pluginManager.executeHook('onEditorMount', context);
		}
		setupEventListeners() {
			this.contentElement.addEventListener('input', (e) => this.handleInput(e));
			this.contentElement.addEventListener('keydown', (e) => this.handleKeyDown(e));
			this.contentElement.addEventListener('keyup', () => this.updateActiveTools());
			this.contentElement.addEventListener('mouseup', () => this.updateActiveTools());
			this.contentElement.addEventListener('focus', () => this.handleFocus());
			this.contentElement.addEventListener('blur', () => this.handleBlur());
			document.addEventListener('selectionchange', () => this.handleSelectionChange());
		}
		handleInput(event) {
			const target = event.target;
			const newContent = this.props.toolbar?.show ? target.innerHTML || '' : target.textContent || '';
			if (this.props.maxLength && !this.props.toolbar?.show && newContent.length > this.props.maxLength) {
				target.textContent = this.lastContentRef;
				return;
			}
			this.lastContentRef = newContent;
			this.updateContent(newContent);
			this.updatePlaceholder();
			this.updateActiveTools();
		}
		handleKeyDown(event) {
			const pluginShortcuts = pluginManager.getKeyboardShortcuts();
			for (const shortcut of pluginShortcuts) {
				const matches =
					event.key.toLowerCase() === shortcut.key.toLowerCase() &&
					!!event.ctrlKey === !!shortcut.ctrlKey &&
					!!event.shiftKey === !!shortcut.shiftKey &&
					!!event.altKey === !!shortcut.altKey &&
					!!event.metaKey === !!shortcut.metaKey;
				if (matches) {
					event.preventDefault();
					shortcut.action(this.getEditorContext());
					return;
				}
			}
			if (this.props.toolbar?.show) {
				const shortcutKey = getShortcutKey(event);
				if (shortcutKey && keyboardShortcuts[shortcutKey]) {
					const tool = keyboardShortcuts[shortcutKey];
					event.preventDefault();
					this.handleToolClick(tool);
					return;
				}
			}
			if (event.metaKey || event.ctrlKey) {
				if (event.key === 'z') {
					if (event.shiftKey) {
						event.preventDefault();
						this.redo();
					} else {
						event.preventDefault();
						this.undo();
					}
				} else if (event.key === 'y') {
					event.preventDefault();
					this.redo();
				}
			}
		}
		handleToolClick(tool) {
			if (!this.contentElement) return;
			this.contentElement.focus();
			if (tool === 'link') {
				const url = prompt('Enter URL:');
				if (url) {
					insertLink(url);
					this.updateContentFromDOM();
				}
			} else if (tool === 'image') {
				const src = prompt('Enter image URL:');
				if (src) {
					insertImage(src);
					this.updateContentFromDOM();
				}
			} else {
				executeFormatCommand(tool);
				this.updateContentFromDOM();
			}
			setTimeout(() => this.updateActiveTools(), 0);
		}
		handleFocus() {
			this.props.onFocus?.();
			this.updateActiveTools();
		}
		handleBlur() {
			this.props.onBlur?.();
		}
		handleSelectionChange() {
			if (!this.contentElement) return;
			const selection = window.getSelection();
			if (!selection || selection.rangeCount === 0) {
				this.state.selection = null;
				return;
			}
			const range = selection.getRangeAt(0);
			if (!this.contentElement.contains(range.commonAncestorContainer)) {
				this.state.selection = null;
				return;
			}
			this.state.selection = {
				start: range.startOffset,
				end: range.endOffset,
			};
			this.props.onSelectionChange?.(this.state.selection);
		}
		updateContent(newContent, addToHistory = true) {
			if (addToHistory && this.state.content !== newContent) {
				const newHistory = {
					...this.state.history,
					undoStack: [
						...this.state.history.undoStack.slice(-(this.state.history.maxHistorySize - 1)),
						this.state.content,
					],
					redoStack: [],
				};
				this.state.history = newHistory;
			}
			this.state.content = newContent;
			this.props.onChange?.(newContent);
			pluginManager.executeHook('onContentChange', newContent, this.getEditorContext());
			this.updateCharCount();
		}
		updateContentFromDOM() {
			if (!this.contentElement) return;
			const newContent = this.props.toolbar?.show
				? this.contentElement.innerHTML || ''
				: this.contentElement.textContent || '';
			this.lastContentRef = newContent;
			this.updateContent(newContent);
		}
		updateActiveTools() {
			if (!this.toolbar || !this.props.toolbar?.tools) return;
			const tools = this.props.toolbar.tools.filter((t) => t !== 'separator');
			const active = getActiveFormats(tools);
			this.toolbar.updateActiveTools(active);
		}
		updatePlaceholder() {
			const placeholder = this.editorWrapper.querySelector('.lilac-editor__placeholder');
			if (placeholder) {
				placeholder.style.display = this.state.content.trim() ? 'none' : 'block';
			}
		}
		updateCharCount() {
			const charCount = this.editorWrapper.querySelector('.lilac-editor__char-count');
			if (charCount && this.props.maxLength) {
				charCount.textContent = `${this.state.content.length}/${this.props.maxLength}`;
			}
		}
		// Public API
		getContent() {
			return this.state.content;
		}
		setContent(content) {
			this.updateContent(content);
			if (this.contentElement) {
				if (this.props.toolbar?.show) {
					this.contentElement.innerHTML = content;
				} else {
					this.contentElement.textContent = content;
				}
				this.lastContentRef = content;
			}
			this.updatePlaceholder();
		}
		focus() {
			this.contentElement?.focus();
		}
		blur() {
			this.contentElement?.blur();
		}
		undo() {
			const { undoStack, redoStack } = this.state.history;
			if (undoStack.length === 0) return;
			const previousContent = undoStack[undoStack.length - 1];
			const newUndoStack = undoStack.slice(0, -1);
			const newRedoStack = [...redoStack, this.state.content];
			this.state.history = {
				...this.state.history,
				undoStack: newUndoStack,
				redoStack: newRedoStack,
			};
			this.state.content = previousContent;
			if (this.contentElement) {
				if (this.props.toolbar?.show) {
					this.contentElement.innerHTML = previousContent;
				} else {
					this.contentElement.textContent = previousContent;
				}
				this.lastContentRef = previousContent;
			}
			this.props.onChange?.(previousContent);
			this.updatePlaceholder();
		}
		redo() {
			const { undoStack, redoStack } = this.state.history;
			if (redoStack.length === 0) return;
			const nextContent = redoStack[redoStack.length - 1];
			const newRedoStack = redoStack.slice(0, -1);
			const newUndoStack = [...undoStack, this.state.content];
			this.state.history = {
				...this.state.history,
				undoStack: newUndoStack,
				redoStack: newRedoStack,
			};
			this.state.content = nextContent;
			if (this.contentElement) {
				if (this.props.toolbar?.show) {
					this.contentElement.innerHTML = nextContent;
				} else {
					this.contentElement.textContent = nextContent;
				}
				this.lastContentRef = nextContent;
			}
			this.props.onChange?.(nextContent);
			this.updatePlaceholder();
		}
		get canUndo() {
			return this.state.history.undoStack.length > 0;
		}
		get canRedo() {
			return this.state.history.redoStack.length > 0;
		}
		setReadOnly(readOnly) {
			this.state.isReadOnly = readOnly;
			this.contentElement.contentEditable = (!readOnly).toString();
			this.editorWrapper.classList.toggle('lilac-editor--readonly', readOnly);
			this.toolbar?.setDisabled(readOnly);
		}
		destroy() {
			pluginManager.executeHook('onEditorUnmount', this.getEditorContext());
			this.editorWrapper.remove();
		}
	};

	// dist/core/index.js
	function injectStyles() {
		if (document.getElementById('lilac-editor-styles')) return;
		const style = document.createElement('style');
		style.id = 'lilac-editor-styles';
		style.textContent = `
/* Lilac Editor Styles - Clean, Modern, Calming */
/* UI Instructions - Keep UI consistent across all adapters */

.lilac-editor {
  --lilac-color-primary: #8b7cd8;
  --lilac-color-primary-light: #a898e8;
  --lilac-color-primary-dark: #6d5ac8;
  --lilac-color-background: #ffffff;
  --lilac-color-surface: #f8f9fb;
  --lilac-color-border: #e1e5e9;
  --lilac-color-border-focus: #8b7cd8;
  --lilac-color-text: #090e12;
  --lilac-color-text-muted: #64748b;
  --lilac-color-text-placeholder: #9ca3af;
  --lilac-color-hover: rgba(0, 0, 0, 0.05);
  --lilac-border-radius: 6px;
  --lilac-border-radius-small: 3px;
  --lilac-shadow: 0 1px 3px rgba(0, 0, 0, 0.1), 0 1px 2px rgba(0, 0, 0, 0.06);
  --lilac-shadow-focus: 0 0 0 3px rgba(139, 124, 216, 0.1);
  --lilac-transition: all 0.2s ease-in-out;
  --lilac-font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
  --lilac-font-size: 16px;
  --lilac-line-height: 1.6;
  --lilac-letter-spacing: -0.01em;
}

.lilac-editor--dark {
  --lilac-color-background: #1a1b23;
  --lilac-color-surface: #252631;
  --lilac-color-border: #3a3b47;
  --lilac-color-border-focus: #a898e8;
  --lilac-color-text: #e2e8f0;
  --lilac-color-text-muted: #94a3b8;
  --lilac-color-text-placeholder: #64748b;
  --lilac-color-hover: rgba(255, 255, 255, 0.05);
  --lilac-shadow: 0 1px 3px rgba(0, 0, 0, 0.3), 0 1px 2px rgba(0, 0, 0, 0.2);
}

.lilac-editor {
  position: relative;
  display: flex;
  flex-direction: column;
  background: var(--lilac-color-background);
  border: 1px solid var(--lilac-color-border);
  border-radius: var(--lilac-border-radius);
  box-shadow: var(--lilac-shadow);
  font-family: var(--lilac-font-family);
  font-size: var(--lilac-font-size);
  line-height: var(--lilac-line-height);
  letter-spacing: var(--lilac-letter-spacing);
  color: var(--lilac-color-text);
  transition: var(--lilac-transition);
  overflow: hidden;
  height: 100%;
  max-height: 100%;
}

.lilac-editor:focus-within {
  border-color: var(--lilac-color-border-focus);
  box-shadow: var(--lilac-shadow), var(--lilac-shadow-focus);
}

.lilac-editor--readonly {
  background: var(--lilac-color-surface);
  cursor: default;
}

.lilac-editor__content-wrapper {
  position: relative;
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.lilac-editor__placeholder {
  position: absolute;
  top: 16px;
  left: 16px;
  color: var(--lilac-color-text-placeholder);
  pointer-events: none;
  font-size: var(--lilac-font-size);
  line-height: var(--lilac-line-height);
  user-select: none;
  z-index: 1;
}

.lilac-editor__content {
  text-align: start;
  padding: 16px;
  flex: 1;
  outline: none;
  word-wrap: break-word;
  overflow-wrap: break-word;
  white-space: pre-wrap;
  position: relative;
  z-index: 2;
  overflow-y: auto;
  overflow-x: hidden;
  height: 100%;
  scrollbar-width: thin;
  scrollbar-color: var(--lilac-color-border) transparent;
}

.lilac-editor__content::-webkit-scrollbar {
  width: 8px;
}

.lilac-editor__content::-webkit-scrollbar-track {
  background: transparent;
}

.lilac-editor__content::-webkit-scrollbar-thumb {
  background: var(--lilac-color-border);
  border-radius: 4px;
}

.lilac-editor__content::-webkit-scrollbar-thumb:hover {
  background: var(--lilac-color-text-muted);
}

.lilac-editor__content h1,
.lilac-editor__content h2,
.lilac-editor__content h3,
.lilac-editor__content h4,
.lilac-editor__content h5,
.lilac-editor__content h6 {
  margin: 1.5em 0 0.75em 0;
  font-weight: 600;
  line-height: 1.4;
  color: var(--lilac-color-text);
}

.lilac-editor__content h1:first-child,
.lilac-editor__content h2:first-child,
.lilac-editor__content h3:first-child {
  margin-top: 0;
}

.lilac-editor__content h1 { font-size: 2em; }
.lilac-editor__content h2 { font-size: 1.5em; }
.lilac-editor__content h3 { font-size: 1.25em; }

.lilac-editor__content p {

  margin: 0 0 1em 0;
}

.lilac-editor__content p:last-child {
  margin-bottom: 0;
}

.lilac-editor__content strong { font-weight: 600; }
.lilac-editor__content em { font-style: italic; }
.lilac-editor__content u { text-decoration: underline; }
.lilac-editor__content s { text-decoration: line-through; }

.lilac-editor__content blockquote {
  margin: 1em 0;
  padding: 0.5em 0 0.5em 1em;
  border-left: 3px solid var(--lilac-color-primary);
  background: var(--lilac-color-surface);
  border-radius: 0 var(--lilac-border-radius-small) var(--lilac-border-radius-small) 0;
  color: var(--lilac-color-text-muted);
}

.lilac-editor__content ul,
.lilac-editor__content ol {
  margin: 1em 0;
  padding-left: 2em;
}

.lilac-editor__content li {
  margin: 0.25em 0;
}

.lilac-editor__content code {
  background: var(--lilac-color-surface);
  padding: 0.125em 0.25em;
  border-radius: var(--lilac-border-radius-small);
  font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, monospace;
  font-size: 0.875em;
}

.lilac-editor__content pre {
  background: var(--lilac-color-surface);
  padding: 1em;
  border-radius: var(--lilac-border-radius-small);
  overflow-x: auto;
  margin: 1em 0;
}

.lilac-editor__content pre code {
  background: none;
  padding: 0;
}

.lilac-editor__content a {
  color: var(--lilac-color-primary);
  text-decoration: underline;
}

.lilac-editor__content a:hover {
  color: var(--lilac-color-primary-dark);
}

.lilac-editor__content img {
  max-width: 100%;
  height: auto;
  border-radius: var(--lilac-border-radius-small);
}

.lilac-editor__content table {
  width: 100%;
  border-collapse: collapse;
  margin: 1em 0;
}

.lilac-editor__content th,
.lilac-editor__content td {
  padding: 8px 12px;
  text-align: left;
  border: 1px solid var(--lilac-color-border);
}

.lilac-editor__content th {
  font-weight: 600;
  background: var(--lilac-color-surface);
}

.lilac-editor__footer {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  padding: 8px 16px;
  background: var(--lilac-color-surface);
  border-top: 1px solid var(--lilac-color-border);
  font-size: 12px;
  position: sticky;
  bottom: 0;
  z-index: 10;
  flex-shrink: 0;
}

.lilac-editor__char-count {
  color: var(--lilac-color-text-muted);
  font-variant-numeric: tabular-nums;
}

.lilac-editor__content:focus { outline: none; }

.lilac-editor__content::selection,
.lilac-editor__content *::selection {
  background: rgba(139, 124, 216, 0.2);
}

/* Toolbar Styles */
.lilac-toolbar {
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 8px 12px;
  background: var(--lilac-color-surface);
  border-bottom: 1px solid var(--lilac-color-border);
  border-radius: var(--lilac-border-radius) var(--lilac-border-radius) 0 0;
  overflow-x: auto;
  scrollbar-width: none;
  -ms-overflow-style: none;
  position: sticky;
  top: 0;
  z-index: 10;
  flex-shrink: 0;
}

.lilac-toolbar::-webkit-scrollbar { display: none; }

.lilac-toolbar--disabled {
  opacity: 0.6;
  pointer-events: none;
}

.lilac-toolbar__button {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  padding: 0;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 6px;
  color: var(--lilac-color-text);
  cursor: pointer;
  transition: all 0.2s cubic-bezier(0.25, 0.8, 0.25, 1);
  font-size: 0;
  outline: none;
  position: relative;
}

.lilac-toolbar__button:hover:not(:disabled):not(.lilac-toolbar__button--disabled) {
  background: var(--lilac-color-background);
  border-color: var(--lilac-color-border);
  color: var(--lilac-color-primary);
  transform: translateY(-1px) scale(1.05);
  box-shadow: 0 2px 8px rgba(139, 124, 216, 0.15);
}

.lilac-toolbar__button:active:not(:disabled):not(.lilac-toolbar__button--disabled) {
  transform: translateY(0) scale(0.98);
}

.lilac-toolbar__button:focus-visible {
  outline: 2px solid var(--lilac-color-primary);
  outline-offset: 1px;
}

.lilac-toolbar__button--active {
  background: var(--lilac-color-primary);
  border-color: var(--lilac-color-primary);
  color: white;
  box-shadow: 0 2px 6px rgba(139, 124, 216, 0.3);
}

.lilac-toolbar__button--active:hover:not(:disabled) {
  background: var(--lilac-color-primary-dark);
  border-color: var(--lilac-color-primary-dark);
  transform: translateY(-1px);
  box-shadow: 0 3px 10px rgba(109, 90, 200, 0.4);
}

.lilac-toolbar__button--disabled {
  opacity: 0.5;
  cursor: not-allowed;
  pointer-events: none;
}

.lilac-toolbar__button svg { flex-shrink: 0; }

.lilac-toolbar__separator {
  width: 1px;
  height: 20px;
  background: var(--lilac-color-border);
  margin: 0 4px;
  flex-shrink: 0;
}

/* Responsive */
@media (max-width: 768px) {
  .lilac-toolbar {
    padding: 6px 8px;
    gap: 1px;
  }
  .lilac-toolbar__button {
    width: 28px;
    height: 28px;
  }
  .lilac-editor__content {
    padding: 12px;
  }
  .lilac-editor__placeholder {
    top: 12px;
    left: 12px;
  }
}

/* Animations */
@keyframes lilac-scale-in {
  from { opacity: 0; transform: scale(0.95); }
  to { opacity: 1; transform: scale(1); }
}

.lilac-editor {
  animation: lilac-scale-in 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
}

@media (prefers-reduced-motion: reduce) {
  .lilac-editor,
  .lilac-toolbar__button {
    animation: none;
    transition: none;
  }
}
  `;
		document.head.appendChild(style);
	}
	return __toCommonJS(index_exports);
})();
