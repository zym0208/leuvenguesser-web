/**
 * Build API URLs that work locally (php -S -t public) and on studev (/public/…).
 * Templates may set window.APP_API.urls with Symfony path() output.
 */
(function (global) {
    const config = global.APP_API || {};

    function detectPrefix() {
        const scripts = document.getElementsByTagName("script");
        for (const script of scripts) {
            const src = script.src || "";
            if (src.includes("/public/js/") || src.includes("/public/assets/")) {
                return "/public";
            }
        }
        return "";
    }

    const prefix = typeof config.prefix === "string" ? config.prefix : detectPrefix();

    /**
     * @param {string} path Absolute path such as "/api/auth/signup"
     * @returns {string}
     */
    function apiUrl(path) {
        const urls = config.urls || {};
        if (urls[path]) {
            return urls[path];
        }
        const normalized = path.startsWith("/") ? path : `/${path}`;
        return `${prefix}${normalized}`;
    }

    /**
     * @param {Response} res
     * @returns {Promise<Record<string, unknown>>}
     */
    async function parseJsonResponse(res) {
        const contentType = res.headers.get("content-type") || "";
        if (!contentType.includes("application/json")) {
            return {};
        }
        try {
            const data = await res.json();
            return data && typeof data === "object" ? data : {};
        } catch {
            return {};
        }
    }

    global.apiUrl = apiUrl;
    global.parseJsonResponse = parseJsonResponse;
})(window);
