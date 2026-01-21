(function () {
	/* FK Popup Management */

	const FKPopupManager = {
		maxPopups: 5,
		openPopups: [], // array of {element, triggerLink, constraintClass}
		popperInstances: new Map(), // Map<element, Popper.Instance>
		fkHandler: null,
		closeHandler: null,
		container: null,

		/**
		 * Initialize the FK popup system
		 */
		init() {
			// Ensure #fk_container and structure exist
			const dataTable = document.querySelector("table#data");
			if (!dataTable) return;

			this.container = document.createElement("div");
			this.container.id = "fk_container";
			//this.container.className = "fk";
			dataTable.parentElement.insertBefore(this.container, dataTable);
			this.container.appendChild(dataTable);

			/*
			const rootDiv = document.createElement("div");
			rootDiv.id = "fk_root";
			wrapper.appendChild(rootDiv);
			*/

			// Attach click handler to all FK links using event delegation
			this.fkHandler = (e) => this.handleFKClick(e);
			document.addEventListener("click", this.fkHandler);

			// Attach close button handler using event delegation
			this.closeHandler = (e) => this.handleCloseClick(e);
			document.addEventListener("click", this.closeHandler);
		},

		/**
		 * Cleanup on unload
		 */
		unload() {
			// Remove event listeners
			if (this.fkHandler) {
				document.removeEventListener("click", this.fkHandler);
				this.fkHandler = null;
			}
			if (this.closeHandler) {
				document.removeEventListener("click", this.closeHandler);
				this.closeHandler = null;
			}

			// Close all open popups
			this.openPopups.forEach((popup) => {
				this.closePopup(popup.element);
			});
			this.openPopups = [];
		},

		/**
		 * Handle FK link click
		 */
		handleFKClick(event) {
			const fkLink = event.target.closest("a.fk");
			if (!fkLink) return;

			console.log("FKPopupManager.handleFKClick");

			event.preventDefault();
			event.stopPropagation();

			document.body.style.cursor = "wait";

			const constraintClass = fkLink.className.split(" ")[1]; // e.g., 'fk_12345'

			// Fetch FK data via AJAX
			const url = new URL(fkLink.dataset.href, window.location.href);
			url.searchParams.set("action", "dobrowsefk");
			console.log("Fetching FK data from:", url.toString());

			fetch(url.toString())
				.then((response) => {
					if (!response.ok)
						throw new Error(
							`HTTP error! status: ${response.status}`,
						);
					return response.text();
				})
				.then((htmlContent) => {
					this.displayPopup(fkLink, constraintClass, htmlContent);
				})
				.catch((error) => {
					const errorMsg = document.createElement("p");
					errorMsg.className = "errmsg";
					errorMsg.textContent =
						Display.errmsg || "Error loading foreign key data";
					this.container.appendChild(errorMsg);
				})
				.finally(() => {
					document.body.style.cursor = "auto";
				});
		},

		/**
		 * Display the FK popup with Popper.js positioning
		 */
		displayPopup(triggerLink, constraintClass, htmlContent) {
			// Check popup cap and close oldest if necessary
			if (this.openPopups.length >= this.maxPopups) {
				const oldest = this.openPopups.shift();
				this.closePopup(oldest.element);
			}

			// Create popup container
			const popupDiv = document.createElement("div");
			popupDiv.className = `fk ${constraintClass}`;
			popupDiv.innerHTML = htmlContent;
			popupDiv.style.position = "absolute";
			popupDiv.style.zIndex = 1000 + this.openPopups.length;

			// Store reference to trigger link on popup element
			popupDiv._triggerLink = triggerLink;
			popupDiv._constraintClass = constraintClass;

			// Append to document body for Popper.js to position
			this.container.appendChild(popupDiv);

			// Initialize Popper.js for positioning
			const popperInstance = Popper.createPopper(triggerLink, popupDiv, {
				placement: "bottom-start",
				modifiers: [
					{
						name: "offset",
						options: {
							offset: [0, 8],
						},
					},
					{
						name: "flip",
						options: {
							padding: 8,
						},
					},
					{
						name: "preventOverflow",
						options: {
							padding: 8,
						},
					},
				],
			});

			this.popperInstances.set(popupDiv, popperInstance);

			// Track open popup
			this.openPopups.push({
				element: popupDiv,
				triggerLink,
				constraintClass,
			});

			// Setup hover highlight effect
			this.setupHighlightEffect(popupDiv, triggerLink, constraintClass);

			// Re-attach close handlers to new close button
			this.attachCloseHandlers(popupDiv);
		},

		/**
		 * Setup highlight effect on hover
		 */
		setupHighlightEffect(popupDiv, triggerLink, constraintClass) {
			popupDiv.addEventListener("mouseenter", () => {
				const row = triggerLink.closest("tr");
				if (row) {
					const refLink = row.querySelector(`a.${constraintClass}`);
					if (refLink) {
						const div = refLink.closest("div");
						if (div) div.classList.add("highlight");
					}
				}
			});

			popupDiv.addEventListener("mouseleave", () => {
				const row = triggerLink.closest("tr");
				if (row) {
					const refLink = row.querySelector(`a.${constraintClass}`);
					if (refLink) {
						const div = refLink.closest("div");
						if (div) div.classList.remove("highlight");
					}
				}
			});
		},

		/**
		 * Attach close button handlers to popup
		 */
		attachCloseHandlers(popupDiv) {
			const closeBtn = popupDiv.querySelector("a.fk_close");
			if (closeBtn) {
				closeBtn.addEventListener("click", (e) => {
					e.preventDefault();
					e.stopPropagation();
					this.closePopup(popupDiv);
				});
			}
		},

		/**
		 * Handle close button click
		 */
		handleCloseClick(event) {
			const closeBtn = event.target.closest("a.fk_close");
			if (!closeBtn) return;

			event.preventDefault();
			event.stopPropagation();

			const popupDiv = closeBtn.closest("div.fk");
			if (popupDiv) {
				this.closePopup(popupDiv);
			}
		},

		/**
		 * Close a popup
		 */
		closePopup(popupDiv) {
			// Remove from tracking array
			const index = this.openPopups.findIndex(
				(p) => p.element === popupDiv,
			);
			if (index !== -1) {
				this.openPopups.splice(index, 1);
			}

			// Destroy Popper instance
			const popperInstance = this.popperInstances.get(popupDiv);
			if (popperInstance) {
				popperInstance.destroy();
				this.popperInstances.delete(popupDiv);
			}

			// Remove highlight from referencing field
			const triggerLink = popupDiv._triggerLink;
			const constraintClass = popupDiv._constraintClass;
			if (triggerLink) {
				const row = triggerLink.closest("tr");
				if (row) {
					const refLink = row.querySelector(`a.${constraintClass}`);
					if (refLink) {
						const div = refLink.closest("div");
						if (div) div.classList.remove("highlight");
					}
				}
			}

			// Remove from DOM
			popupDiv.remove();
		},
	};

	// Initialize FK popup system
	FKPopupManager.init();

	/* End FK Popup Management */

	/* Column Sorting Management */

	const reverseSortDir = {
		asc: "desc",
		desc: "asc",
	};

	let tooltipTimout = 0;

	// Adjust orderby fields in links before sending them out
	document.querySelectorAll("a.orderby").forEach((a) => {
		a.addEventListener("click", (e) => {
			//e.preventDefault();
			//e.stopPropagation();
			const col = a.dataset.col;
			const url = new URL(a.href, window.location.origin);
			const params = new URLSearchParams(url.search);
			const initialDir = /date|timestamp/.test(a.dataset.type)
				? "desc"
				: "asc";

			let orderby = {};
			for (const [key, val] of params.entries()) {
				const match = key.match(/^orderby\[(.+)]$/);
				if (match) orderby[match[1]] = val;
			}

			if (!orderby[col]) {
				// set reversed here, because it get reversed later again
				orderby[col] = reverseSortDir[initialDir];
			}

			//console.log(orderby);

			if (e.ctrlKey) {
				delete orderby[col];
			} else if (e.shiftKey) {
				orderby[col] = reverseSortDir[orderby[col]];
			} else {
				const direction = reverseSortDir[orderby[col]];
				orderby = {};
				orderby[col] = direction;
			}

			//console.log(orderby);

			[...params.keys()].forEach((k) => {
				if (k.startsWith("orderby[")) params.delete(k);
			});
			params.delete("orderby_clear");
			for (const [c, dir] of Object.entries(orderby)) {
				params.set(`orderby[${c}]`, dir);
			}
			if (Object.keys(orderby).length === 0) {
				params.set("orderby_clear", "1");
			}

			url.search = params.toString();
			a.href = url.toString();

			//console.log(url.toString());
		});

		a.addEventListener("mouseenter", () => {
			tooltipTimout = window.setTimeout(() => {
				window.showTooltip(a, a.closest("tr").dataset.orderbyDesc);
			}, 500);
		});

		a.addEventListener("mouseleave", () => {
			window.clearTimeout(tooltipTimout);
			window.hideTooltip();
		});
	});

	// Ensure initialization only happens once
	if (!window.displayJsInitialized) window.displayJsInitialized = true;
	else return;

	// Virtual Frame Load Event
	document.addEventListener("frameLoaded", function (e) {
		//window.clearTimeout(tooltipTimout);
		//window.hideTooltip();
	});

	// Virtual Frame Unload Event
	document.addEventListener("beforeFrameUnload", () => {
		FKPopupManager.unload();
		window.clearTimeout(tooltipTimout);
		window.hideTooltip();
	});

	/* End Column Sorting Management */

	/* SQL Quoting Helpers */
	window.pgQuoteIdent = function (ident) {
		return '"' + String(ident).replace(/"/g, '""') + '"';
	};

	window.pgQuoteLiteral = function (value) {
		if (value === null || value === undefined || value === "") {
			return "NULL";
		}
		return "'" + String(value).replace(/'/g, "''") + "'";
	};

	/* Popup Field Editor Management */
	const FieldPopupEditor = {
		currentPopup: null,
		popperInstance: null,
		currentCell: null,
		originalValue: null,

		init() {
			const dataTable = document.querySelector("table#data");
			if (!dataTable) return;

			// Attach double-click handler using event delegation
			dataTable.addEventListener("dblclick", (e) =>
				this.handleDoubleClick(e),
			);

			// Attach click-outside handler
			document.addEventListener("click", (e) =>
				this.handleOutsideClick(e),
			);

			// Attach keydown handler for Escape
			document.addEventListener("keydown", (e) => this.handleKeydown(e));
		},

		handleDoubleClick(e) {
			const cell = e.target.closest("td.editable");
			if (!cell) return;

			e.preventDefault();
			e.stopPropagation();

			this.openEditor(cell);
		},

		handleOutsideClick(e) {
			if (!this.currentPopup) return;

			// Check if click is outside popup
			if (!this.currentPopup.contains(e.target)) {
				this.saveAndClose();
			}
		},

		handleKeydown(e) {
			if (!this.currentPopup) return;

			if (e.key === "Escape") {
				e.preventDefault();
				this.close();
			} else if (e.key === "Enter" && !e.shiftKey) {
				const target = e.target;
				// Allow Enter in textareas
				if (target.tagName === "TEXTAREA") return;
				e.preventDefault();
				this.saveAndClose();
			}
		},

		openEditor(cell) {
			// Close existing popup
			if (this.currentPopup) {
				this.close();
			}

			const fieldName = cell.dataset.name;
			const fieldType = cell.dataset.type;
			const row = cell.closest("tr");
			const table = cell.closest("table#data");

			if (!fieldName || !row || !table) return;

			const schema = table.dataset.schema;
			const tableName = table.dataset.table;

			if (!schema || !tableName) {
				console.error("Missing schema or table information");
				return;
			}

			// Get row keys
			const keysJson = row.dataset.keys;
			if (!keysJson) {
				console.error("Missing row keys");
				return;
			}

			this.currentCell = cell;

			// Build URL
			const params = new URLSearchParams({
				action: "popupedit",
				server: new URLSearchParams(window.location.search).get(
					"server",
				),
				database: new URLSearchParams(window.location.search).get(
					"database",
				),
				schema: schema,
				table: tableName,
				field: fieldName,
				keys: keysJson,
			});

			// Fetch editor HTML
			fetch("display.php?" + params.toString())
				.then((response) => {
					if (!response.ok) throw new Error("Failed to load editor");
					return response.text();
				})
				.then((html) => {
					this.showPopup(
						cell,
						html,
						row,
						schema,
						tableName,
						fieldName,
					);
				})
				.catch((err) => {
					console.error("Error loading editor:", err);
				});
		},

		showPopup(cell, html, row, schema, tableName, fieldName) {
			// Create popup element
			const popup = document.createElement("div");
			popup.className = "field-popup-container";
			popup.innerHTML = html;
			document.body.appendChild(popup);

			this.currentPopup = popup;

			// Create Popper instance
			if (window.Popper) {
				this.popperInstance = window.Popper.createPopper(cell, popup, {
					placement: "bottom-start",
					modifiers: [
						{
							name: "flip",
							options: {
								fallbackPlacements: [
									"top-start",
									"bottom-end",
									"top-end",
								],
							},
						},
						{
							name: "preventOverflow",
							options: {
								padding: 8,
							},
						},
					],
				});
			}

			// Focus input
			const input = popup.querySelector("input, textarea, select");
			if (input) {
				setTimeout(() => {
					input.focus();
					if (input.select) input.select();
				}, 50);
			}

			// Store row data for SQL generation
			popup.dataset.schema = schema;
			popup.dataset.table = tableName;
			popup.dataset.field = fieldName;
			popup.dataset.keys = row.dataset.keys || "{}";
		},

		saveAndClose() {
			if (!this.currentPopup) return;

			const schema = this.currentPopup.dataset.schema;
			const table = this.currentPopup.dataset.table;
			const field = this.currentPopup.dataset.field;
			const keys = JSON.parse(this.currentPopup.dataset.keys || "{}");

			// Get input value
			const input = this.currentPopup.querySelector(
				'input[name="value"], textarea[name="value"], select[name="value"]',
			);
			const nullCheckbox =
				this.currentPopup.querySelector("#popup-null-cb");
			const exprCheckbox =
				this.currentPopup.querySelector("#popup-expr-cb");

			if (!input) {
				this.close();
				return;
			}

			const newValue = input.value;
			const isNull = nullCheckbox && nullCheckbox.checked;
			const isExpr = exprCheckbox && exprCheckbox.checked;

			// Generate UPDATE SQL
			let sql =
				"UPDATE " +
				window.pgQuoteIdent(schema) +
				"." +
				window.pgQuoteIdent(table) +
				" SET ";

			// SET clause
			if (isNull) {
				sql += window.pgQuoteIdent(field) + " = NULL";
			} else if (isExpr) {
				sql += window.pgQuoteIdent(field) + " = " + newValue;
			} else {
				sql +=
					window.pgQuoteIdent(field) +
					" = " +
					window.pgQuoteLiteral(newValue);
			}

			// WHERE clause
			const whereParts = [];
			for (const [keyName, keyValue] of Object.entries(keys)) {
				// Extract field name from "key[fieldname]" format
				const match = keyName.match(/^key\[(.+)\]$/);
				if (!match) continue;
				const fieldName = match[1];

				if (keyValue === null || keyValue === "NULL") {
					whereParts.push(
						window.pgQuoteIdent(fieldName) + " IS NULL",
					);
				} else {
					whereParts.push(
						window.pgQuoteIdent(fieldName) +
							" = " +
							window.pgQuoteLiteral(keyValue),
					);
				}
			}

			if (whereParts.length > 0) {
				sql += " WHERE " + whereParts.join(" AND ");
			}

			sql += ";";

			// Update query editor and submit form
			if (typeof setEditorValue === "function") {
				setEditorValue("query-editor", sql);
			}

			const form = document.getElementById("query-form");
			if (form) {
				form.submit();
			}

			this.close();
		},

		close() {
			if (this.popperInstance) {
				this.popperInstance.destroy();
				this.popperInstance = null;
			}

			if (this.currentPopup) {
				this.currentPopup.remove();
				this.currentPopup = null;
			}

			this.currentCell = null;
			this.originalValue = null;
		},

		unload() {
			this.close();
		},
	};

	// Initialize on load
	FieldPopupEditor.init();

	// Cleanup on unload
	document.addEventListener("beforeFrameUnload", () => {
		FieldPopupEditor.unload();
	});
})();
