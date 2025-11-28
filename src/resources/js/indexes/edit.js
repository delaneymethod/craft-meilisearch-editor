/**
 * Meilisearch Editor for Craft CMS.
 *
 * @author        DelaneyMethod
 * @copyright     Copyright (c) 2025
 *
 * @see           https://github.com/delaneymethod/craft-meilisearch-editor
 */

(function ($) {
	$(function () {
		const FIELD_TYPE_NEO = 'benf\\neo\\Field';
		const FIELD_TYPE_ASSETS = 'craft\\fields\\Assets';
		const FIELD_TYPE_MATRIX = 'craft\\fields\\Matrix';
		const FIELD_TYPE_SUPERTABLE = 'verbb\\supertable\\fields\\SuperTableField';

		const API_ENDPOINT_ENTRY_TYPES = 'meilisearch-editor/api/entry-types';
		const API_ENDPOINT_FIELDS = 'meilisearch-editor/api/fields';
		const API_ENDPOINT_FIELDS_NESTED = 'meilisearch-editor/api/fields/nested';
		const API_ENDPOINT_IMAGE_TRANSFORMS = 'meilisearch-editor/api/image-transforms';

		const label = $('#meilisearch-editor-label');
		const handle = $('#meilisearch-editor-handle');

		const siteAwareFieldLightswitch = $('#meilisearch-editor-site-aware-field .lightswitch');

		const singleSiteField = $('#meilisearch-editor-single-site-field');
		const multipleSiteField  = $('#meilisearch-editor-multiple-site-field');

		// Sections fieldset (inner <fieldset id="meilisearch-editor-sections">)
		const sectionFieldset = $('#meilisearch-editor-sections');

		// Where we inject dynamically built Craft-style blocks
		const entryTypesField = $('#meilisearch-editor-entry-types-field');
		const fieldsField = $('#meilisearch-editor-fields-field');
		const imageTransformsField = $('#meilisearch-editor-image-transforms-field');

		const meilisearchEditorEditIndex = window?.meilisearchEditorEditIndex || {};

		const entryTypesLabel = Craft.t('meilisearch-editor', 'Entry Types');
		const entryTypesInstructions = Craft.t('meilisearch-editor', 'Choose which entry types you want to use.');
		const entryTypesNotFound = Craft.t('meilisearch-editor', 'No entry types found. Please select a section above.');
		const entryTypesFetchingError = Craft.t('meilisearch-editor', 'Failed to fetch entry types.');

		const fieldsLabel = Craft.t('meilisearch-editor', 'Fields');
		const fieldsInstructions = Craft.t('meilisearch-editor', 'Choose which fields you want to use.');
		const fieldsNotFound = Craft.t('meilisearch-editor', 'No fields found. Please select an entry type above.');
		const fieldsFetchingError = Craft.t('meilisearch-editor', 'Failed to fetch fields.');

		const nestedFieldsFetchingError = Craft.t('meilisearch-editor', 'Failed to load nested fields.');

		const imageTransformsLabel = Craft.t('meilisearch-editor', 'Image Transforms');
		const imageTransformsInstructions = Craft.t('meilisearch-editor', 'Choose which image transforms to generate for selected asset fields above.');
		const imageTransformsNotFound = Craft.t('meilisearch-editor', 'No image transforms found. Please select an asset field above.');
		const imageTransformsFetchingError = Craft.t('meilisearch-editor', 'Failed to fetch image transforms.')

		const headers = () => ({
			'Accept': 'application/json',
			'Content-Type': 'application/json',
			'X-Requested-With': 'XMLHttpRequest',
			'X-CSRF-Token': Craft?.csrfTokenValue,
		});

		const getEntryTypeNamespace = (sectionNamespace, entryTypeHandle) => `${sectionNamespace}.${entryTypeHandle}`;

		const getFieldNamespace = (entryTypeNamespace, fieldPath) => `${entryTypeNamespace}.${fieldPath}`;

		const getChecked = (container, selector) => container && selector && container.find(`${selector}:checked`).get();

		const getCheckedValues = (container, selector) => container && selector && container.find(`${selector}:checked`).map((index, element) => element?.value).get();

		const isBlockLike = fieldType => (fieldType === FIELD_TYPE_MATRIX || fieldType === FIELD_TYPE_NEO || fieldType === FIELD_TYPE_SUPERTABLE);

		const getFieldMarkup = (fieldId, fieldUid, fieldLabel, fieldHandle, fieldValue, fieldType, fieldAllowedKinds, fieldNamespace, fieldChecked, fieldEntryTypeHandle) => `
			<input type="checkbox" id="${fieldId}" class="checkbox" name="fields[${fieldEntryTypeHandle}][]" value="${fieldValue}" data-label="${fieldLabel}" ${fieldAllowedKinds} ${fieldNamespace} ${fieldType} ${fieldUid} ${fieldChecked}>
			<label id="${fieldId}-label" for="${fieldId}">${fieldLabel}</label>
		`;

		const updateSiteAwareUI = ()=> {
			if (!siteAwareFieldLightswitch || !singleSiteField || !multipleSiteField) {
				return;
			}

			const isOn = siteAwareFieldLightswitch.hasClass('on');

			singleSiteField.toggle(!isOn);
			multipleSiteField.toggle(isOn);
		};

		const removeNestedFields = field => {
			if (!field) {
				return;
			}

			const nestedFieldsSlot = field.closest('.checkbox-select-item').find('.nested-fields-slot');
			if (nestedFieldsSlot) {
				nestedFieldsSlot.find('input[type="checkbox"][name^="fields["]').prop('checked', false);
				nestedFieldsSlot.empty();
			}
		};

		const renderEntryTypes = (sections, preselectedEntryTypes = []) => {
			if (!sections?.length && entryTypesField?.length) {
				entryTypesField.html(`<p class="light italic">${entryTypesNotFound}</p>`);

				return;
			}

			let html = '';

			sections.forEach(({ section, entryTypes = [] }, index) => {
				const sectionName = Craft.escapeHtml(section?.name);

				const id = `meilisearch-editor-entry-types-${section?.handle}`;
				const fieldsetId = `meilisearch-editor-entry-types-${section?.handle}`;

				let extraAttributes = '';
				if (index > 0) {
					extraAttributes = 'style="margin-top: 8px;"';
				}

				html += `
					<div class="heading" ${extraAttributes}>
						<legend id="${id}-label">${entryTypesLabel} <span style="font-weight: normal;">(${sectionName})</span></legend>
						<div class="instructions">
							<p>${entryTypesInstructions}</p>
						</div>
					</div>
					<div class="input ltr">
						<fieldset id="${fieldsetId}" class="checkbox-select">
							<input type="hidden" name="entryTypes[]" value="">
							${entryTypes.map(entryType => {
								const entryTypeLabel = Craft.escapeHtml(entryType?.name ?? entryType?.handle);
								const entryTypeHandle = `data-entry-type-handle="${entryType.handle}"`;
								const entryTypeId = `entryTypes_${section?.handle}_${entryType?.handle}`;
								const entryTypeValue = getEntryTypeNamespace(section?.handle, entryType?.handle);
								const entryTypeNamespace = `data-entry-type-namespace="${entryTypeValue}"`;
								const entryTypeSectionHandle = `data-section-handle="${section?.handle}"`;
								const entryTypeChecked = preselectedEntryTypes.includes(entryTypeValue) || preselectedEntryTypes.includes(entryType?.handle) ? 'checked' : '';

								return `
									<div class="checkbox-select-item">
										<input type="checkbox" id="${entryTypeId}" class="checkbox" name="entryTypes[]" value="${entryTypeValue}" ${entryTypeSectionHandle} ${entryTypeHandle} ${entryTypeNamespace} ${entryTypeChecked}>
										<label id="${entryTypeId}-label" for="${entryTypeId}">${entryTypeLabel}</label>
									</div>
								`;
							}).join('')}
						</fieldset>
					</div>
				`;
			});

			if (entryTypesField?.length) {
				entryTypesField.html(html);
			}
		};

		const renderFields = entryTypes => {
			if (!entryTypes?.length && fieldsField.length) {
				fieldsField.html(`<p class="light">${fieldsNotFound}</p>`);

				return;
			}

			let html = '';

			const preselectedMap = meilisearchEditorEditIndex?.fieldsNamespaced ?? meilisearchEditorEditIndex?.fields ?? {};

			entryTypes.forEach(({ entryType, fields = [] }, index) => {
				const entryName = Craft.escapeHtml(entryType?.name);
				const entryTypeHandle = entryType?.handle;
				const sectionHandle = $(`[data-entry-type-handle="${entryType?.handle}"]`).attr('data-section-handle');
				const id = `meilisearch-editor-fields-${entryTypeHandle}`;
				const fieldsetId = `meilisearch-editor-fields-${entryTypeHandle}`;
				const selected = preselectedMap[entryTypeHandle] || [];

				let extraAttributes = '';
				if (index > 0) {
					extraAttributes = 'style="margin-top: 8px;"';
				}

				html += `
					<div class="heading" ${extraAttributes}>
						<legend id="${id}-label">${fieldsLabel} <span style="font-weight: normal;">(${entryName})</span></legend>
						<div class="instructions">
							<p>${fieldsInstructions}</p>
						</div>
					</div>
					<div class="input ltr">
						<fieldset id="${fieldsetId}" class="checkbox-select">
							<input type="hidden" name="fields[${entryTypeHandle}]" value="">
							${fields.map(field => {
								const entryTypeValue = getEntryTypeNamespace(sectionHandle, entryTypeHandle);

								const fieldId = `fields_${entryTypeHandle}_${field?.handle}`;
								const fieldUid = field?.uid ? `data-field-uid="${field.uid}"` : '';
								const fieldLabel = Craft.escapeHtml(field?.name ?? field?.handle);
								const fieldHandle = field?.handle;
								const fieldValue = getFieldNamespace(entryTypeValue, field?.handle);
								const fieldType = field?.type ? `data-field-type="${field.type}"` : '';
								const fieldAllowedKinds = field?.allowedKinds ? `data-allowed-kinds="${field.allowedKinds}"` : '';
								const fieldNamespace = `data-field-namespace="${fieldValue}"`;
								const fieldChecked = selected.includes(fieldValue) || selected.includes(field?.handle) ? 'checked' : '';
								const fieldMarkup = getFieldMarkup(fieldId, fieldUid, fieldLabel, fieldHandle, fieldValue, fieldType, fieldAllowedKinds, fieldNamespace, fieldChecked, entryTypeHandle);

								return `
									<div class="checkbox-select-item" ${isBlockLike(field?.type) ? 'style="display: block;"' : ''}>
										${fieldMarkup}
										<div class="nested-fields-slot"></div>
									</div>
								`;
  						}).join('')}
						</fieldset>
					</div>
				`;
			});

			if (fieldsField?.length) {
				fieldsField.html(html);
			}
		};

		const renderNestedFields = fields => {
			const parentField = fields?.parentField;
			const nestedFields = fields?.nestedFields;
			if (!parentField || !nestedFields) {
				return;
			}

			let html = '';

			const field = $(`[data-field-uid="${parentField?.uid}"]`);
			const fieldsetId = field.closest('fieldset').attr('id') || '';
			const nestedFieldsSlot = field.closest('.checkbox-select-item').find('.nested-fields-slot');
			const matches = fieldsetId.match(/^meilisearch-editor-fields-(.+)$/);
			const entryTypeHandle = matches ? matches[1] : '';
			const sectionHandle = $(`[data-entry-type-handle="${entryTypeHandle}"]`).attr('data-section-handle') || meilisearchEditorEditIndex?.sections?.[0];
			const entryTypeNamespace = `${sectionHandle}.${entryTypeHandle}`;
			const preselectedMap = meilisearchEditorEditIndex?.fieldsNamespaced ?? meilisearchEditorEditIndex?.fields ?? {};
			const preselectedPaths = (preselectedMap?.[entryTypeHandle] || []).filter(path => path.startsWith(field.val() + '.'));

			nestedFields.map((nestedField, index) => {
				const id = `nested-${parentField?.handle}-${nestedField?.entryType?.handle}`;

				const items = nestedField?.fields.map(fieldsField => {
					const fieldPath = `${parentField?.handle}.${nestedField?.entryType?.handle}.${fieldsField?.handle}`;
					const fieldId = `fields_${entryTypeHandle}_${fieldPath.replace(/\./g, '_')}`;
					const fieldUid = fieldsField?.uid ? `data-field-uid="${fieldsField.uid}"` : '';
					const fieldLabel = Craft.escapeHtml(`${parentField?.name} \u2192 ${nestedField?.entryType?.name} \u2192 ${fieldsField?.name}`);
					const fieldHandle = fieldsField?.handle;
					const fieldValue = getFieldNamespace(entryTypeNamespace, fieldPath);
					const fieldType = fieldsField?.type ? `data-field-type="${fieldsField.type}"` : '';
					const fieldAllowedKinds = fieldsField?.allowedKinds ? `data-allowed-kinds="${fieldsField.allowedKinds}"` : '';
					const fieldChecked = preselectedPaths.includes(fieldValue) || preselectedPaths.includes(fieldPath) ? 'checked' : '';
					const fieldNamespace = `data-field-namespace="${fieldValue}"`;
					const fieldMarkup = getFieldMarkup(fieldId, fieldUid, fieldLabel, fieldHandle, fieldValue, fieldType, fieldAllowedKinds, fieldNamespace, fieldChecked, entryTypeHandle);

					return `
        		<div class="checkbox-select-item">
        			${fieldMarkup}
        		</div>
      		`;
				}).join('');

				let extraAttributes = 'style="margin-left:22px;margin-top: 8px;"';
				if (index > 0) {
					extraAttributes = 'style="margin-left:22px;margin-top: 0;"';
				}

				html += `
					<div id="${id}" ${extraAttributes}>
						<div class="input ltr">
							<fieldset class="checkbox-select">
								${items}
							</fieldset>
						</div>
      		</div>
    		`;
			});

			if (nestedFieldsSlot?.length) {
				nestedFieldsSlot.html(html);
			}
		};

		const renderImageTransforms = (fields, imageTransforms) => {
			if (!fields || !imageTransforms && imageTransformsField?.length) {
				// imageTransformsField.html(`<p class="light">${imageTransformsNotFound}</p>`);

				return;
			}

			const id = 'meilisearch-editor-image-transforms';
			const preselectedImageTransforms = meilisearchEditorEditIndex?.imageTransforms || {};

			const groups = fields.map(field => {
				field = $(field).get(0);

				const fieldLabel = field.getAttribute('data-label');
				const fieldHandle = field?.value;
				const fieldSelected = new Set(preselectedImageTransforms?.[fieldHandle] || []);

				const optionsHtml = imageTransforms.map(imageTransform => {
					let sourceLabel = '';
					if (imageTransform?.source === 'craft') {
						sourceLabel = 'Craft';
					} else if (imageTransform?.source === 'imager-x') {
						sourceLabel = 'Imager X';
					}

					const optionKey = `${imageTransform?.source}:${imageTransform?.handle}`;
					const optionLabel = Craft.escapeHtml(imageTransform?.name + ' (' + sourceLabel + ')');
					const optionValue = Craft.escapeHtml(`${fieldHandle}::${optionKey}`);
					const optionIsSelected = fieldSelected.has(optionKey) || fieldSelected.has(imageTransform?.handle) ? 'selected' : '';

					return `<option value="${optionValue}" ${optionIsSelected}>${optionLabel}</option>`;
				}).join('');

				return `<optgroup label="${Craft.escapeHtml(fieldLabel)}">${optionsHtml}</optgroup>`;
			}).join('');

			const html = `
				<div class="heading">
					<legend id="${id}-label">${imageTransformsLabel}</legend>
					<div class="instructions">
						<p>${imageTransformsInstructions}</p>
					</div>
				</div>
				<div class="input ltr">
					<div class="fullwidth multiselect">
						<select id="${id}" name="imageTransforms[]" aria-labelledby="${id}-label" multiple="" size="10">
							${groups}
						</select>
					</div>
				</div>
			`;

			if (imageTransformsField?.length) {
				imageTransformsField.html(html);
			}
		};

		const fetchEntryTypes = sections => {
			if (entryTypesField?.length) {
				entryTypesField.empty();
			}

			if (fieldsField?.length) {
				fieldsField.empty();
			}

			if (!sections?.length) {
				return;
			}

			return fetch(Craft.getCpUrl(API_ENDPOINT_ENTRY_TYPES), {
				method: 'POST',
				headers: headers(),
				body: JSON.stringify({ sections }),
				credentials: 'same-origin',
			})
				.then(response => response.json())
				.then(json => {
					let preselectedEntryTypes = meilisearchEditorEditIndex?.entryTypesNamespaced ?? meilisearchEditorEditIndex?.entryTypes ?? [];
					preselectedEntryTypes = preselectedEntryTypes.filter(entryType => entryType && typeof entryType === 'string' && entryType.trim() !== '');

					renderEntryTypes(json?.sections, preselectedEntryTypes);

					let entryTypes = preselectedEntryTypes ?? getCheckedValues(entryTypesField, 'input[name="entryTypes[]"]');
					if (entryTypes.length) {
						// Convert Entry Type Namespace -> plain handles
						entryTypes = entryTypes.map(entryType => (entryType && entryType.includes('.')) ? entryType.split('.').pop() : entryType);

						fetchFields(entryTypes);
					}
				})
				.catch(error => {
					Craft.cp?.displayError?.(entryTypesFetchingError);

					console.error(error);
				});
		};

		const fetchFields = entryTypes => {
			if (fieldsField?.length) {
				fieldsField.empty();
			}

			if (!entryTypes?.length) {
				return;
			}

			return fetch(Craft.getCpUrl(API_ENDPOINT_FIELDS), {
				method: 'POST',
				headers: headers(),
				body: JSON.stringify({ entryTypes }),
				credentials: 'same-origin',
			})
				.then(response => response.json())
				.then(json => {
					renderFields(json?.entryTypes)

					const preselectedFields = getChecked(fieldsField, 'input[type="checkbox"][name^="fields["]');
					if (preselectedFields?.length) {
						preselectedFields.forEach(preselectedField => {
							preselectedField = $(preselectedField);
							if (preselectedField.is(':checked') && isBlockLike(preselectedField.data('field-type'))) {
								fetchNestedFields(preselectedField);
							}
						});

						fetchImageTransforms(preselectedFields);
					}
				})
				.catch(error => {
					Craft.cp?.displayError?.(fieldsFetchingError);

					console.error(error);
				});
		};

		const fetchNestedFields = field => {
			if (!field) {
				return;
			}

			const type = field.data('field-type') ?? '';
			const fieldUid = field.data('field-uid') ?? '';

			return fetch(Craft.getCpUrl(API_ENDPOINT_FIELDS_NESTED), {
				method: 'POST',
				headers: headers(),
				body: JSON.stringify({
					type,
					fieldUid,
				}),
				credentials: 'same-origin',
			})
				.then(response => response.json())
				.then(json => renderNestedFields(json?.fields))
				.catch(error => {
					Craft.cp?.displayError?.(nestedFieldsFetchingError);

					console.error(error);
				});
		};

		const fetchImageTransforms = fields => {
			if (imageTransformsField.length) {
				imageTransformsField.empty();
			}

			fields = fields.filter(field => {
				const element = field instanceof $ ? field[0] : field;
				const allowedKindsRaw = (element.getAttribute('data-allowed-kinds') || '');
				const allowedKinds = allowedKindsRaw.split(',').map(value => value.trim()).filter(Boolean);
				const type = element.getAttribute('data-field-type') || '';
				const isAssets = type === FIELD_TYPE_ASSETS;
				const allowsImages = allowedKinds.length === 0 || allowedKinds.includes('image') || allowedKinds.includes('images');

				return isAssets && allowsImages;
			});

			if (!fields.length) {
				return;
			}

			return fetch(Craft.getCpUrl(API_ENDPOINT_IMAGE_TRANSFORMS), {
				method: 'POST',
				headers: headers(),
				body: JSON.stringify({}), // no filter needed
				credentials: 'same-origin',
			})
				.then(response => response.json())
				.then(json => renderImageTransforms(fields, json?.imageTransforms))
				.catch(error => {
					Craft.cp?.displayError?.(imageTransformsFetchingError);

					console.error(error);
				});
		};

		if (handle.length && !handle.val()) {
			new Craft.SlugGenerator(label, handle, { charMap: null });
		}

		if (siteAwareFieldLightswitch.length) {
			siteAwareFieldLightswitch.on('click', updateSiteAwareUI).on('keydown', event => {
				if (event.key === ' ' || event.key === 'Enter') {
					setTimeout(updateSiteAwareUI, 0);
				}
			});

			// also observe programmatic changes (Craft can toggle it)
			if (siteAwareFieldLightswitch[0]) {
				new MutationObserver(updateSiteAwareUI).observe(siteAwareFieldLightswitch[0], {
					attributes: true,
					attributeFilter: ['class'],
				});
			}

			updateSiteAwareUI();
		}

		// Sections -> Entry Types
		if (sectionFieldset.length) {
			const preselectedSections = meilisearchEditorEditIndex?.sections ?? getCheckedValues(sectionFieldset, 'input[name="sections[]"]');
			if (preselectedSections) {
				fetchEntryTypes(preselectedSections);
			}

			sectionFieldset.on('change', 'input[type="checkbox"][name^="sections[]"]', () => {
				const selectedSections = getCheckedValues(sectionFieldset, 'input[name="sections[]"]');
				if (!selectedSections) {
					return;
				}

				fetchEntryTypes(selectedSections);
			});
		}

		// Entry Types -> Fields
		if (entryTypesField.length) {
			entryTypesField.on('change', 'input[name^="entryTypes[]"]', () => {
				const entryTypes = entryTypesField.find('input[type="checkbox"][name="entryTypes[]"]:checked').map((index, element) => element.getAttribute('data-entry-type-handle')).get();
				if (!entryTypes) {
					return;
				}

				fetchFields(entryTypes);
			});
		}

		// Fields -> Nested (Matrix/Neo/SuperTable) + Image Transforms for Assets
		if (fieldsField.length) {
			fieldsField.on('change', 'input[type="checkbox"][name^="fields["]', function() {
				const field = $(this);
				const fields = getChecked(fieldsField, 'input[type="checkbox"][name^="fields["]');

				if (isBlockLike(field.data('field-type'))) {
					if (field.is(':checked')) {
						fetchNestedFields(field);
					} else {
						removeNestedFields(field);
					}
				}

				fetchImageTransforms(fields);
			});
		}
	});
})(jQuery);
