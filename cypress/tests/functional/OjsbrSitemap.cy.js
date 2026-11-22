/**
 * @file cypress/tests/functional/OjsbrSitemap.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests of the sitemap of a journal with the plugin on, read as a search
 * engine reads it: login, registration, search, current issue and galleys are not listed;
 * the editorial masthead is; no address is listed twice; lastmod is a day (Y-m-d) and the
 * home page has one; and in a journal with more than one language every address links to
 * itself in each language and to x-default.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (captcha on login must be off
 * for the run). The defaults match the data set of PKP's continuous integration, and the
 * first test enables the plugin when it is off.
 */

describe('OJSBR Sitemap', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';
	const rowName = 'ojsbrsitemapplugin';

	// ---- OJSBR spec helpers (padrão v2): work on OJS/OMP 3.3, 3.4 and 3.5 and in PKP's CI ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	// Same as PKP's cy.waitJQuery(), which the support files of OJS 3.3 test sites may lack.
	// The Plugins tab can keep requests open for a while (the plugin gallery), hence the timeout.
	const waitJQuery = () => cy.window().its('jQuery.active', {timeout: 60000}).should('eq', 0);

	// Requests carry the browser's User-Agent: OJS 3.3 drops a session whose agent changes.
	const request = (options) => cy.window({log: false}).then((win) => cy.request(Object.assign(
		typeof options === 'string' ? {url: options} : options,
		{headers: Object.assign({'User-Agent': win.navigator.userAgent}, (typeof options === 'string' ? {} : options.headers) || {})}
	)));

	// Signs in through requests (the login page can re-render while it is typed into), then
	// falls back to the form when the session did not stick (OJS 3.3 cookie handling).
	const login = (username, password) => {
		cy.clearCookies();
		request(pageUrl('login')).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			// The form posts to the URL with the language: a redirect would turn the POST into a GET.
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: username, password: password}, log: false});
		});
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('body').then(($body) => {
			if ($body.find('form#login').length) {
				cy.get('form#login input[name="username"]').type(username, {delay: 0});
				cy.get('form#login input[name="password"]').type(password, {delay: 0, log: false});
				cy.get('form#login').submit();
				cy.get('form#login', {timeout: 30000}).should('not.exist');
			}
		});
	};

	// REST API calls made from the page itself, so they carry the browser's own session.
	const api = (path, options = {}) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, Object.assign({credentials: 'same-origin'}, options)).then((response) => {
			if (!response.ok) {
				return response.text().then((text) => {
					throw new Error(path + ' answered ' + response.status + ': ' + text.slice(0, 300));
				});
			}
			return response.json();
		}),
		{log: false, timeout: 30000}
	));

	// The website settings page on its Plugins tab (a new query string forces a load). Load it
	// once per test: loading it again while its plugin gallery request is pending stalls the
	// web server of PKP's CI; API calls and settings modals work on the page already open.
	const openPluginsTab = () => {
		cy.visit(pageUrl('management/settings/website') + '?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.get('button[id="plugins-button"]').should('have.attr', 'aria-selected', 'true');
		waitJQuery();
	};

	// Enables the plugin in the grid when it is off (never turns it off).
	const enablePlugin = (rowName) => {
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]', {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				waitJQuery();
			}
		});
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]').should('be.checked');
	};

	// ---- end of helpers ----

	const sitemapNamespace = 'http://www.sitemaps.org/schemas/sitemap/0.9';
	const xhtmlNamespace = 'http://www.w3.org/1999/xhtml';

	// The sitemap of the journal as an anonymous visitor gets it, parsed.
	const sitemap = () => cy.request({url: pageUrl('sitemap') + '?reload=' + Date.now(), followRedirect: true}).then((response) => {
		expect(response.status).to.eq(200);
		const doc = new DOMParser().parseFromString(response.body, 'application/xml');
		expect(doc.getElementsByTagName('parsererror'), 'well-formed XML').to.have.length(0);
		const urls = Array.from(doc.getElementsByTagNameNS(sitemapNamespace, 'url')).map((url) => ({
			loc: url.getElementsByTagNameNS(sitemapNamespace, 'loc')[0].textContent.trim(),
			lastmod: url.getElementsByTagNameNS(sitemapNamespace, 'lastmod')[0]?.textContent ?? null,
			links: Array.from(url.getElementsByTagNameNS(xhtmlNamespace, 'link')).map((link) => link.getAttribute('hreflang')),
		}));
		return {body: response.body, urls: urls};
	});

	it('Enables the plugin', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		enablePlugin(rowName);
	});

	it('Leaves out the pages that are not content and lists the editorial masthead once', function() {
		sitemap().then(({urls}) => {
			const locs = urls.map((url) => url.loc);
			expect(locs.length, 'addresses').to.be.greaterThan(1);
			expect(new Set(locs).size, 'no address listed twice').to.eq(locs.length);
			locs.forEach((loc) => {
				expect(loc).not.to.match(/\/(login|search|user\/register|issue\/current)$/);
				expect(loc, 'galley').not.to.match(/\/article\/view\/[^/]+\/[^/]+$/);
			});
			expect(locs.filter((loc) => /\/about\/editorialMasthead$/.test(loc)), 'editorial masthead').to.have.length(1);
		});
	});

	it('Dates the pages by the day of their content', function() {
		sitemap().then(({urls}) => {
			const dated = urls.filter((url) => url.lastmod !== null);
			expect(dated.length, 'pages with lastmod').to.be.greaterThan(0);
			dated.forEach((url) => expect(url.lastmod, url.loc).to.match(/^\d{4}-\d{2}-\d{2}$/));
			expect(urls[0].lastmod, 'home page').to.match(/^\d{4}-\d{2}-\d{2}$/);
			const latest = dated.map((url) => url.lastmod).sort().pop();
			expect(urls[0].lastmod, 'the home page has the latest date').to.eq(latest);
			urls.filter((url) => /\/about(\/submissions|\/contact|\/editorialMasthead)?$/.test(url.loc))
				.forEach((url) => expect(url.lastmod, url.loc).to.eq(null));
		});
	});

	it('Links every page to itself in the languages of the journal', function() {
		sitemap().then(({body, urls}) => {
			const languages = urls[0].links.length;
			if (languages === 0) {
				// One language: no links, and nothing else to check.
				expect(body).not.to.contain('xmlns:xhtml');
				return;
			}
			expect(body).to.contain('xmlns:xhtml="' + xhtmlNamespace + '"');
			expect(urls[0].links).to.include('x-default');
			urls.forEach((url) => expect(url.links, url.loc).to.have.length(languages));
		});
	});
});
