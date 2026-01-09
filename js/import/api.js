import { el } from "./utils.js";

// Server parameter helpers

export function appendServerToUrl(url) {
	const SERVER = el("importForm").server.value;
	const DATABASE = el("importForm").database?.value;
	const SCHEMA = el("importForm").schema?.value;
	url += url.indexOf("?") === -1 ? "?" : "&";
	url += "server=" + encodeURIComponent(SERVER);
	if (DATABASE) url += "&database=" + encodeURIComponent(DATABASE);
	if (SCHEMA) url += "&schema=" + encodeURIComponent(SCHEMA);
	return url;
}

export function appendServerToParams(params) {
	const SERVER = el("importForm").server.value;
	const DATABASE = el("importForm").database?.value;
	const SCHEMA = el("importForm").schema?.value;
	try {
		if (params instanceof URLSearchParams || params instanceof FormData) {
			params.append("server", SERVER);
			if (DATABASE) params.append("database", DATABASE);
			if (SCHEMA) params.append("schema", SCHEMA);
			return params;
		}
	} catch (e) {
		// ignore
	}
	if (typeof params === "string") {
		params += "&server=" + encodeURIComponent(SERVER);
		if (DATABASE) params += "&database=" + encodeURIComponent(DATABASE);
		if (SCHEMA) params += "&schema=" + encodeURIComponent(SCHEMA);
	}
	return params;
}
