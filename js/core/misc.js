(function () {
	// Multi-form toggle
	window.toggleAllMf = function (bool) {
		var inputs = document
			.getElementById("multi_form")
			.getElementsByTagName("input");

		for (var i = 0; i < inputs.length; i++) {
			if (inputs[i].type == "checkbox") inputs[i].checked = bool;
		}
		return false;
	};

	/**
	 * SQL query extractor
	 * @param {string} sql
	 * @returns {string[]}
	 */
	window.extractSqlQueries = function (sql) {
		const queries = [];
		let current = "";
		let inString = false;
		let stringChar = null;
		let inLineComment = false;
		let inBlockComment = false;

		for (let i = 0; i < sql.length; i++) {
			const c = sql[i];
			const n = sql[i + 1];

			// Line comment --
			if (!inString && !inBlockComment && c === "-" && n === "-") {
				inLineComment = true;
			}

			// End line comment
			if (inLineComment && c === "\n") {
				inLineComment = false;
			}

			// Block comment /*
			if (!inString && !inLineComment && c === "/" && n === "*") {
				inBlockComment = true;
			}

			// End block comment */
			if (inBlockComment && c === "*" && n === "/") {
				inBlockComment = false;
				i++;
				continue;
			}

			// Strings '...' or "..."
			if (!inLineComment && !inBlockComment) {
				if (!inString && (c === "'" || c === '"')) {
					inString = true;
					stringChar = c;
				} else if (inString && c === stringChar) {
					inString = false;
				}
			}

			// Semicolon ends query
			if (!inString && !inLineComment && !inBlockComment && c === ";") {
				if (current.trim().length > 0) {
					queries.push(current.trim());
				}
				current = "";
				continue;
			}

			current += c;
		}

		if (current.trim().length > 0) {
			queries.push(current.trim());
		}

		return queries;
	};

	window.isSqlReadQuery = function (sql) {
		const statements = extractSqlQueries(sql);
		//console.log("Extracted statements:", statements);
		if (statements.length === 0) {
			return false;
		}

		for (const stmt of statements) {
			const upper = stmt.toUpperCase();

			if (
				upper.startsWith("SELECT") ||
				upper.startsWith("WITH") ||
				upper.startsWith("SET") ||
				upper.startsWith("SHOW")
			) {
				continue; // ok
			}

			if (upper.startsWith("EXPLAIN")) {
				const rest = upper.slice(7).trim();
				if (rest.startsWith("SELECT")) {
					continue; // ok
				}
			}

			return false;
		}

		return true;
	};

	/**
	 * @param {HTMLElement} element
	 * @param {Object} options
	 */
	function createDateTimePickerInternal(element, options) {
		// Check if flatpickr is already initialized
		if (element._flatpickr) {
			return;
		}

		const originalValue = element.value;
		element.value = "";

		const sharedOptions = {
			clickOpens: false,
			allowInput: true,
			defaultDate: element.value || null,

			onChange: (selectedDates, dateStr, instance) => {
				const cbExpr = document.getElementById(
					"cb_expr_" + element.dataset.field
				);
				if (cbExpr) cbExpr.checked = false;
				const cbNull = document.getElementById(
					"cb_null_" + element.dataset.field
				);
				if (cbNull) cbNull.checked = false;
				const selFnc = document.getElementById(
					"sel_fnc_" + element.dataset.field
				);
				if (selFnc) selFnc.value = "";
			},

			onReady: (selectedDates, dateStr, instance) => {
				element.value = originalValue;
			},
		};

		options = { ...options, ...sharedOptions };

		const fp = flatpickr(element, options);

		// Create wrapper container
		let container = document.createElement("div");
		container.classList.add("date-picker-input-container");

		// Create button
		let button = document.createElement("div");
		button.className = "date-picker-button";
		button.innerHTML = "📅";

		element.parentNode.insertBefore(container, element);

		// Move input into container
		container.appendChild(element);
		container.appendChild(button);

		button.addEventListener("click", () => {
			// Make input readonly while picker is open
			element.readOnly = true;
			fp.open();
			fp.config.onClose.push(() => {
				element.readOnly = false;
			});
		});

		element.addEventListener("click", () => fp.close());
	}

	/**
	 * Format: [+-]0001-12-11[ BC]
	 * @param {HTMLElement} element
	 */
	window.createDatePicker = function (element) {
		const options = {
			dateFormat: "Y-m-d",

			parseDate: (datestr, format) => {
				element.dataset.date = datestr;
				const clean = datestr
					.replace(/^[-+]\d{4}/, (match) => match.slice(1)) // strip sign from year
					.replace(/\s?(BC|AD)$/i, ""); // strip era
				return flatpickr.parseDate(clean, format) ?? new Date();
			},

			formatDate: (date, format, locale) => {
				const prevDateStr = element.dataset.date ?? "";
				let datestr = flatpickr.formatDate(date, format, locale);

				const prefixMatch = prevDateStr.match(/^[-+]/);
				if (prefixMatch) {
					datestr = prefixMatch[0] + datestr;
				}

				const match = prevDateStr.match(/\s?(BC|AD)$/i);
				if (match) {
					datestr += match[0];
				}

				return datestr;
			},
		};

		createDateTimePickerInternal(element, options);
	};

	/**
	 * Format: [+-]0001-12-11 19:35:00[+02][ BC]
	 * @param {HTMLElement} element
	 */
	window.createDateTimePicker = function (element) {
		const options = {
			enableTime: true,
			enableSeconds: true,
			time_24hr: true,
			dateFormat: "Y-m-d H:i:S",
			minuteIncrement: 1,
			defaultHour: 0,

			parseDate: (datestr, format) => {
				//console.log(datestr);
				// Save original string for later reconstruction
				element.dataset.date = datestr;

				// Strip sign from year, timezone, and BC/AD suffix
				const clean = datestr
					.replace(/^([-+])(\d{4})/, "$2") // remove leading +/-
					.replace(/([+-]\d{2}:?\d{2}|Z)?\s?(BC|AD)?$/i, ""); // remove tz + era

				return flatpickr.parseDate(clean.trim(), format) ?? new Date();
			},

			formatDate: (date, format, locale) => {
				const prevDateStr = element.dataset.date ?? "";
				//console.log(prevDateStr);
				//console.log(new Error());
				let datestr = flatpickr.formatDate(date, format, locale);

				// Reattach sign if original year had one
				const prefixMatch = prevDateStr.match(/^[-+]/);
				if (prefixMatch) {
					datestr = prefixMatch[0] + datestr;
				}

				// Reattach timezone and/or BC/AD suffix if present
				const match = prevDateStr.match(
					/([+-]\d{2}(:?\d{2})?|Z)?(\s?(BC|AD))?$/
				);
				if (match && match[1]) {
					datestr += match[1];
				}
				if (match && match[2]) {
					datestr += match[1];
				}

				return datestr;
			},
		};

		createDateTimePickerInternal(element, options);
	};

	/**
	 * @param {HTMLElement} element
	 */
	function createSqlEditor(element) {
		if (element.classList.contains("ace_editor")) {
			// Editor already created
			return;
		}
		const editorDiv = document.createElement("div");
		editorDiv.className = element.className;
		//editorDiv.style.width = textarea.style.width || "100%";
		//editorDiv.style.height = textarea.style.height || "100px";

		const hidden = document.createElement("input");
		hidden.type = "hidden";
		hidden.name = element.name;
		hidden.onchange = element.onchange;
		hidden.dataset.hideFromUrl = true;

		// copy data- attributes
		for (const [key, value] of Object.entries(element.dataset)) {
			hidden.dataset[key] = value;
		}

		element.insertAdjacentElement("afterend", editorDiv);
		editorDiv.insertAdjacentElement("afterend", hidden);
		element.remove();

		const editor = ace.edit(editorDiv);
		editor.setShowPrintMargin(false);
		editor.session.setUseWrapMode(true);
		//editor.session.setMode("ace/mode/pgsql");
		const mode = element.dataset.mode || "pgsql";
		editor.session.setMode("ace/mode/" + mode);
		//editor.setTheme("ace/theme/tomorrow");
		editor.setHighlightActiveLine(false);
		editor.renderer.$cursorLayer.element.style.display = "none";
		editor.setValue(element.value || "", -1);
		editor.setReadOnly(element.hasAttribute("readonly"));
		editor.setOptions({
			enableBasicAutocompletion: true,
			enableSnippets: true,
			enableLiveAutocompletion: true,
		});

		editor.session.on("change", function () {
			hidden.value = editor.getValue();
			if (hidden.onchange) {
				hidden.onchange();
			}
		});

		editor.on("blur", () => {
			editor.setHighlightActiveLine(false);
			editor.renderer.$cursorLayer.element.style.display = "none";
		});

		editor.on("focus", () => {
			editor.setHighlightActiveLine(true);
			editor.renderer.$cursorLayer.element.style.display = "";
		});

		hidden.value = editor.getValue();

		if (element.classList.contains("auto-expand")) {
			// We resize the editor height according to content but not below
			// the height that is defined in CSS
			const lineHeight = editor.renderer.lineHeight;
			const lineCount = editor.session.getLength();
			const cssHeight = parseInt(
				getComputedStyle(editor.container).height,
				10
			);
			const padding = 4;
			const newHeight = Math.max(
				cssHeight,
				lineCount * lineHeight + padding
			);
			editor.container.style.height = newHeight + "px";
			editor.resize();
		}
	}

	/**
	 * @param {HTMLElement} element
	 */
	function createSqlViewer(element) {
		if (element.dataset.hljsInitialized) {
			return;
		}
		element.dataset.hljsInitialized = "1";

		const language = element.dataset.language || "pgsql";
		if (language === "plpgsql") language = "pgsql";
		element.classList.add(`language-${language}`);
		console.log("SQL Viewer language:", language);

		// Apply syntax highlighting

		hljs.highlightElement(element);

		if (language === "pgsql") {
			// Quoted identifiers
			element.innerHTML = element.innerHTML.replace(
				/"([\w.]+)"/g,
				'<span class="hljs-quoted-identifier">"$1"</span>'
			);
		}
	}

	/**
	 *
	 * @param {HTMLElement} rootElement
	 */
	function createSqlEditors(rootElement) {
		rootElement.querySelectorAll(".sql-editor").forEach((element) => {
			//console.log(element);
			createSqlEditor(element);
		});

		const elements = Array.from(
			rootElement.querySelectorAll(".sql-viewer")
		);
		processInIdle(elements, createSqlViewer);
	}

	/**
	 *
	 * @param {HTMLElement} rootElement
	 */
	function createDateAndTimePickers(rootElement) {
		rootElement
			.querySelectorAll("input[data-type^=timestamp]")
			.forEach((element) => {
				//console.log(element);
				createDateTimePicker(element);
			});
		rootElement
			.querySelectorAll("input[data-type^=date]")
			.forEach((element) => {
				//console.log(element);
				createDatePicker(element);
			});
	}

	function highlightDataFields(rootElement) {
		const rePgDateTime =
			/^(?=(?:\d{4}-\d{2}-\d{2})|(?:\d{2}:\d{2}:\d{2}))(?:(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2}))?(?:\s*(?<hour>\d{2}):(?<minute>\d{2}):(?<second>\d{2})(?:\.(?<ms>\d+))?(?<tz>[+-]\d{2})?)?$/;

		const elements = Array.from(
			rootElement.querySelectorAll(".field.highlight-datetime")
		);
		processInIdle(elements, (element) => {
			const text = element.textContent.trim();
			console.log("Checking datetime field:", text);

			let m = text.match(rePgDateTime);
			if (m) {
				const groups = m.groups;
				console.log("Matched datetime groups:", groups);
				let html = "";
				if (groups.year) {
					html += '<span class="dt-date">';
					html += `<span class="dt-year">${groups.year}</span>-`;
					html += `<span class="dt-month">${groups.month}</span>-`;
					html += `<span class="dt-day">${groups.day}</span>`;
					html += "</span>";
					if (groups.hour) {
						html += " ";
					}
				}
				if (groups.hour) {
					html += '<span class="dt-time">';
					html += `<span class="dt-hour">${groups.hour}</span>:`;
					html += `<span class="dt-minute">${groups.minute}</span>:`;
					html += `<span class="dt-second">${groups.second}</span>`;
					if (groups.ms) {
						html += `.<span class="dt-ms">${groups.ms}</span>`;
					}
					if (groups.tz) {
						html += `<span class="dt-tz">${groups.tz}</span>`;
					}
					html += "</span>";
				}
				element.innerHTML = html;
			}
		});
	}

	// Tooltips

	const tooltip = document.getElementById("tooltip");
	const tooltipContent = document.getElementById("tooltip-content");
	let popperInstance = null;

	window.showTooltip = function (referenceEl, text) {
		console.log("show tooltip", referenceEl);
		text = text || referenceEl.dataset.desc || "Description missing!";
		if (!/<\w+/.test(text)) {
			// plain text, convert line endings into html breaks
			text = text.replace(/\n/g, "<br>\n");
		}
		tooltipContent.innerHTML = text;
		tooltip.style.display = "block";

		if (popperInstance) {
			popperInstance.destroy();
		}

		popperInstance = Popper.createPopper(referenceEl, tooltip, {
			placement: "top",
		});
	};

	window.hideTooltip = function () {
		tooltip.style.display = "none";
		if (popperInstance) {
			popperInstance.destroy();
			popperInstance = null;
		}
	};

	// Virtual Frame Event

	document.addEventListener("frameLoaded", function (e) {
		console.log("Frame loaded:", e.detail.url);
		createSqlEditors(e.target);
		createDateAndTimePickers(e.target);
		highlightDataFields(e.target);
	});

	// Helper to process elements in idle time

	function processInIdle(chunks, fn) {
		const scheduleIdleWork = (cb) => {
			return setTimeout(() => {
				const start = performance.now();
				cb({
					didTimeout: false,
					timeRemaining: () =>
						Math.max(0, 10 - (performance.now() - start)),
				});
			}, 0);
		};

		const run = (deadline) => {
			while (deadline.timeRemaining() > 0 && chunks.length > 0) {
				fn(chunks.shift());
			}
			if (chunks.length > 0) {
				scheduleIdleWork(run);
			}
		};
		scheduleIdleWork(run);
	}

	// Initialization

	flatpickr.localize(flatpickr.l10ns.default);
	//createSqlEditors(document.documentElement);
	//createDateAndTimePickers(document.documentElement);
	window.createSqlEditor = createSqlEditor;
	window.createSqlViewer = createSqlViewer;

	hljs.registerLanguage("pgsql", function (hljs) {
		const base = hljs.getLanguage("pgsql");

		// Kopie der bestehenden Grammatik
		const newGrammar = Object.assign({}, base);

		// Neue Funktionserkennungs-Regel hinzufügen
		newGrammar.contains = [
			{
				className: "function",
				begin: /\b[a-zA-Z_][a-zA-Z0-9_]*\s*(?=\()/, // identifier followed by "("
			},
			{
				className: "string",
				begin: /\$\$/,
			},
			{
				className: "symbol",
				begin: /\$\d+/,
			},
			...base.contains,
		];

		return newGrammar;
	});
})();
