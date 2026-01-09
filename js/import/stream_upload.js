// Streaming uploader using new process_chunk endpoint
// Sends uncompressed chunks and prepends server-provided remainder.
// Minimal v1: no IndexedDB persistence yet.

import {
	el,
	formatBytes,
	sniffMagicType,
	getServerCaps,
	fnv1a64,
} from "./utils.js";
import { appendServerToUrl } from "./api.js";

function utf8Encode(str) {
	return new TextEncoder().encode(str);
}

function utf8Decode(bytes) {
	return new TextDecoder().decode(bytes);
}

function concatBytes(a, b) {
	const out = new Uint8Array(a.length + b.length);
	out.set(a, 0);
	out.set(b, a.length);
	return out;
}

async function startStreamUpload() {
	const fileInput = el("file");
	if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
		alert("No file selected");
		return;
	}
	const file = fileInput.files[0];
	const hash = fnv1a64(
		utf8Encode(file.name + "|" + file.size + "|" + file.lastModified)
	);

	// Stable per-import session key so multiple uploads can run in parallel.
	const importSessionId = `import-${hash}`;
	//const importSessionId = `import-${Math.random().toString(16).slice(2)}`;
	console.log("Import session id:", importSessionId);

	// Basic caps & type checks
	const caps = getServerCaps(fileInput);
	const magic = await sniffMagicType(file);
	if (magic === "gzip" || magic === "bzip2" || magic === "zip") {
		alert(
			"Compressed imports not yet supported in streaming mode. Please decompress locally and retry."
		);
		return;
	}

	const scope = el("import_scope")?.value || "database";
	const scopeIdent = el("import_scope_ident")?.value || "";

	// Options
	const stopOnError = !!document.querySelector(
		"input[name='opt_stop_on_error']"
	)?.checked;
	const importForm = el("importForm");
	const optNames = [
		"opt_roles",
		"opt_tablespaces",
		"opt_databases",
		"opt_schema_create",
		"opt_data",
		"opt_truncate",
		"opt_ownership",
		"opt_rights",
		"opt_defer_self",
		"opt_allow_drops",
	];
	const opts = {};
	for (const n of optNames) {
		const ck = importForm[n];
		if (ck && ck.checked) opts[n] = 1;
	}
	console.log("Import options:", opts);

	// UI elements
	const importUI = el("importUI");
	const uploadPhase = el("uploadPhase");
	const importPhase = el("importPhase");
	const importProgress = el("importProgress");
	const importStatus = el("importStatus");
	const importLog = el("importLog");

	if (importUI) importUI.style.display = "block";
	if (uploadPhase) uploadPhase.style.display = "none";
	if (importPhase) importPhase.style.display = "block";
	if (importLog) importLog.textContent = "";

	// Chunk size from config attrs
	const chunkAttr = fileInput.dataset
		? fileInput.dataset.importChunkSize
		: null;
	const chunkSize = (chunkAttr && parseInt(chunkAttr, 10)) || 5 * 1024 * 1024;

	let offset = 0;
	let remainder = ""; // server-provided incomplete tail
	const skipInput = document.querySelector("input[name='skip_statements']");
	let stuckCount = 0;

	async function step() {
		const prevOffset = offset;
		const prevRemainderLen = remainder ? utf8Encode(remainder).length : 0;
		const end = Math.min(offset + chunkSize, file.size);
		const blob = file.slice(offset, end);
		const bytes = new Uint8Array(await blob.arrayBuffer());

		const remBytes = remainder ? utf8Encode(remainder) : new Uint8Array(0);
		const payload = remBytes.length ? concatBytes(remBytes, bytes) : bytes;

		// Build URL
		const params = new URLSearchParams();
		params.set("offset", String(offset));
		params.set("remainder_len", String(remBytes.length));
		if (end >= file.size) params.set("eof", "1");
		params.set("scope", scope);
		params.set("import_session_id", importSessionId);
		// include current database so server reconnects to the right DB
		const dbEl = el("import_database");
		const dbFromUI =
			(dbEl && dbEl.value) ||
			document.getElementById("importUI")?.dataset?.database ||
			"";
		if (dbFromUI) params.set("database", dbFromUI);
		if (scopeIdent) params.set("scope_ident", scopeIdent);
		if (stopOnError) params.set("opt_stop_on_error", "1");
		const skipCount = parseInt(skipInput?.value || "0", 10) || 0;
		if (skipCount > 0) params.set("skip", String(skipCount));
		for (const [k, v] of Object.entries(opts)) params.set(k, String(v));

		const url = appendServerToUrl(
			"dbimport.php?action=process_chunk&" + params.toString()
		);

		const resp = await fetch(url, {
			method: "POST",
			body: payload,
			headers: { "Content-Type": "application/octet-stream" },
		});
		if (!resp.ok) {
			const text = await resp.text();
			throw new Error("Server error: " + text);
		}
		const res = await resp.json();

		// Update state
		// Prefer server-provided remainder string (required when server transforms/normalizes remainder during streaming, and for future compressed streaming).
		let remainderLenServer =
			typeof res.remainder_len === "number" ? res.remainder_len : 0;
		if (typeof res.remainder === "string") {
			remainder = res.remainder;
			remainderLenServer = utf8Encode(remainder).length;
		} else {
			remainder = "";
		}
		offset = typeof res.offset === "number" ? res.offset : offset;

		// Detect stall: no forward progress on either file offset OR remainder reduction.
		// (At EOF, the only progress possible is that the remainder shrinks.)
		if (offset === prevOffset && remainderLenServer === prevRemainderLen) {
			stuckCount++;
		} else {
			stuckCount = 0;
		}

		// UI progress
		if (importProgress) {
			const pct = Math.floor((offset / file.size) * 100);
			importProgress.value = pct;
		}
		if (importStatus) {
			importStatus.textContent = `Processed ${formatBytes(
				offset
			)} / ${formatBytes(file.size)}`;
		}
		// Log
		if (Array.isArray(res.logEntries) && importLog) {
			for (const e of res.logEntries) {
				const msg = e.message || e.statement || "";
				if (msg && typeof msg === "string") {
					// normalize timestamp: server now sends milliseconds since epoch
					let timeMs;
					if (typeof e.time === "number") {
						// if the value looks like seconds (<= 1e11), convert to ms
						timeMs = e.time > 1e11 ? e.time : e.time * 1000;
					} else {
						timeMs = Date.now();
					}
					const line = `[${new Date(timeMs).toISOString()}] ${
						e.type || "info"
					}: ${msg}`;
					importLog.textContent += line + "\n";
				}
			}
			importLog.scrollTop = importLog.scrollHeight;
		}

		// Continue if not done
		// Stop if completed or if we appear stuck for multiple iterations
		const done = offset >= file.size && remainderLenServer === 0;
		if (done) return false;

		// If we've reached EOF and have nothing new to send, but the server can't reduce the remainder,
		// this is an unterminated/unsupported tail (not a recoverable stall).
		if (
			offset >= file.size &&
			bytes.length === 0 &&
			remainderLenServer > 0 &&
			remainderLenServer === prevRemainderLen
		) {
			throw new Error(
				`Reached end of file but ${remainderLenServer} bytes of SQL remain unprocessed. ` +
					"The dump may be missing a terminating ';' or contains an unsupported trailing construct."
			);
		}

		if (stuckCount >= 3) {
			throw new Error(
				"Import appears stalled (no progress). The file may contain a very large statement without a terminator or an unsupported COPY block."
			);
		}
		return true;
	}

	try {
		while (true) {
			const cont = await step();
			if (!cont) break;
			// small pacing to keep UI responsive
			await new Promise((r) => setTimeout(r, 100));
		}
		if (importStatus) importStatus.textContent += " — Completed";
	} catch (err) {
		console.error(err);
		alert("Import failed: " + (err?.message || err));
	}
}

function wireButton() {
	const btn = el("importStart");
	if (!btn) return;
	btn.addEventListener("click", (ev) => {
		ev.preventDefault();
		startStreamUpload();
	});
}

document.addEventListener("frameLoaded", wireButton);
