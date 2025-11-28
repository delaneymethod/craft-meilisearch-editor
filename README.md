# Meilisearch Editor (Craft CMS)

Manage your Meilisearch content without a developer.

## Install

### From the Plugin Store

Go to the Plugin Store in your project's Control Panel and search for "meilisearch editor". Then press "Install".

### With Composer

Add the repo to your project and install via Composer:

```bash
cd /path/to/project

composer require delaneymethod/meilisearch-editor && php craft plugin/install meilisearch-editor
```

## Console commands (useful in CI)

```bash
php craft meilisearch-editor/index/delete --handle=my-index --dryRun=0 		// Deletes index.
php craft meilisearch-editor/index/flush --handle=my-index --dryRun=0		// Flushes index documents.
php craft meilisearch-editor/index/reindex --handle=my-index --dryRun=0		// Rebuilds index.
```

## Frontend: minimal example

```twig
{% set siteId = craft.app.sites.currentSite.id %}
{% set index = craft.meilisearchEditor.getIndex('devices') %}

{% set filters = craft.meilisearchEditor.getFilters(index.handle) %}
{% set sortables = craft.meilisearchEditor.getSortables(index.handle) %}

<h1>Meilisearch Demo – {{ index.label }}</h1>

<form style="display: grid; gap: 0.75rem; grid-template-columns: 1fr 1fr 1fr; max-width: 980px; margin: 1rem 0;">
	<label style="display: flex; flex-direction: column; gap: 0.25rem;">
		<span>Search</span>
		<input type="search" id="query" name="query" placeholder="Search..." value="" />
	</label>

	{% if filters|length %}
		{% for filter in filters %}
			<label style="display:flex; flex-direction:column; gap:.25rem;">
				<span>{{ filter.name }}</span>
				<select id="{{ 'filter-' ~ filter.id }}" data-attribute="{{ filter.id }}">
					<option value="">All</option>
					{% for option in filter.options %}
						<option value="{{ option.label }}">{{ option.label }}</option>
					{% endfor %}
				</select>
			</label>
		{% endfor %}
	{% endif %}

	<label style="display:flex; flex-direction:column; gap:.25rem;">
		<span>Sort</span>
		<select id="{{ 'sort-' ~ index.handle }}">
			<option value="">Relevance</option>
			{% for sortable in sortables %}
				<option value="{{ sortable.value }}">{{ sortable.label }}</option>
			{% endfor %}
		</select>
	</label>
</form>

<pre id="results" style="background: #f2f2f2; color: #9f9f9f; padding: 1rem; border-radius: 8px; max-width: 980px; overflow: auto;"></pre>

<script>
	(async () => {
		// Get a short-lived search key
		const response = await fetch('{{ url("meilisearch-editor/keys/issue") }}', {
		method: "POST",
		headers: {
			"Accept": "application/json",
			"Content-Type": "application/json",
			"X-CSRF-Token": "{{ craft.app.request.csrfToken }}",
		},
		body: JSON.stringify({
			name: "Demo Search Key",
			handle: "{{ index.handle }}",
			actions: ["search"],
			ttl: 900,
		})
	});

	const json = await response.json();
	if (!response.ok || !json.key) {
		console.error("Key issue failed:", json);

		return;
	}

	const { key, indexes } = json;
	const indexUid = indexes[0];

	const base = "{{ url("meilisearch") }}";
	const searchUrl = `${base}/indexes/${encodeURIComponent(indexUid)}/search`;

	const headers = {
		"Accept": "application/json",
		"Authorization": `Bearer ${key}`,
		"Content-Type": "application/json",
	};

	const debounce = (fn, ms = 200) => {
		let t;
		return (...args) => {
			clearTimeout(t);
			t = setTimeout(() => fn(...args), ms);
		};
	};

	const results = document.querySelector("#results");
	const queryInput = document.querySelector("#query");
	const sortSelect = document.querySelector("#{{ 'sort-' ~ index.handle }}");
	const filterSelects = Array.from(document.querySelectorAll('select[id^="filter-"]'));

	function buildFilters() {
		const filters = [];

		filterSelects.forEach(select => {
			const attribute = select.dataset.attribute;
			const value = select.value;

			if (!attribute || !value) {
				return;
			}
		
			const safeValue = value.replace(/"/g, '\\"');
			filters.push(`${attribute} = '${safeValue}'`);
		});

		return filters;
	}

	async function doSearch() {
		const q = queryInput ? (queryInput.value || "") : "";
		const filters = buildFilters();
		const sortValue = sortSelect && sortSelect.value ? [sortSelect.value] : undefined;
	
		const payload = {
			q,
			limit: 10,
			...(filters.length ? { filter: filters } : {}),
			...(sortValue ? { sort: sortValue } : {}),
		};

		const response = await fetch(searchUrl, {
			method: "POST",
			headers,
			body: JSON.stringify(payload),
		});

		const json = await response.json();
		results.textContent = JSON.stringify(json.hits ?? json, null, 2);
	}

	if (queryInput) {
		queryInput.addEventListener("input", debounce(doSearch, 200));
	}

	filterSelects.forEach(select => select.addEventListener("change", doSearch));

	if (sortSelect) {
		sortSelect.addEventListener("change", doSearch);
	}

	await doSearch();
})();
</script>
```
