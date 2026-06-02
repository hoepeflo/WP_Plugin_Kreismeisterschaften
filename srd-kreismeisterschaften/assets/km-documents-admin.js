(function ($) {
	'use strict';

	function toggleDocFields($row) {
		var type = $row.find('.srd-km-doc-type-select').val();
		$row.find('.srd-km-doc-field--pdf').toggle(type === 'pdf');
		$row.find('.srd-km-doc-field--page').toggle(type === 'page');
		$row.find('.srd-km-doc-field--url').toggle(type === 'url');
	}

	function initRow($row) {
		toggleDocFields($row);
	}

	function reindexCategoryRows() {
		var $tbody = $('#srd-km-category-docs-tbody');
		if (!$tbody.length) {
			return;
		}
		$tbody.find('tr.srd-km-category-doc-row').each(function (index) {
			var $row = $(this);
			$row.find('[name^="srd_km_documents["]').each(function () {
				var name = $(this).attr('name');
				if (!name) {
					return;
				}
				var match = name.match(/^srd_km_documents\[[^\]]+\](.*)$/);
				if (match) {
					var key = $row.attr('data-doc-key');
					$(this).attr('name', 'srd_km_documents[' + key + ']' + match[1]);
				}
			});
			$row.find('[name^="srd_km_documents_files["]').each(function () {
				var name = $(this).attr('name');
				if (!name) {
					return;
				}
				var match = name.match(/^srd_km_documents_files\[[^\]]+\](.*)$/);
				if (match) {
					var key = $row.attr('data-doc-key');
					$(this).attr('name', 'srd_km_documents_files[' + key + ']' + match[1]);
				}
			});
			$row.find('input[name="srd_km_category_order[]"]').val($row.attr('data-doc-key'));
		});
	}

	function initSortable() {
		var $tbody = $('#srd-km-category-docs-tbody');
		if (!$tbody.length || typeof $.fn.sortable !== 'function') {
			return;
		}
		$tbody.sortable({
			handle: '.srd-km-doc-drag-handle',
			axis: 'y',
			placeholder: 'srd-km-doc-sort-placeholder',
			forcePlaceholderSize: true,
			update: reindexCategoryRows,
		});
	}

	function addCustomRow() {
		var $tbody = $('#srd-km-category-docs-tbody');
		var $tpl = $('#srd-km-custom-doc-row-template');
		if (!$tbody.length || !$tpl.length) {
			return;
		}
		var key = 'custom_' + Date.now().toString(36);
		var html = $tpl.html().replace(/__KEY__/g, key);
		var $row = $(html);
		$tbody.append($row);
		initRow($row);
		reindexCategoryRows();
	}

	$(function () {
		$('.srd-km-documents-admin-table tbody tr').each(function () {
			initRow($(this));
		});

		$(document).on('change', '.srd-km-doc-type-select', function () {
			initRow($(this).closest('tr'));
		});

		initSortable();

		$('#srd-km-add-custom-doc').on('click', function (e) {
			e.preventDefault();
			addCustomRow();
		});

		$(document).on('click', '.srd-km-remove-custom-doc', function (e) {
			e.preventDefault();
			if (!window.confirm(window.srdKmDocumentsAdmin?.strings?.confirmRemove || 'Eintrag entfernen?')) {
				return;
			}
			$(this).closest('tr.srd-km-category-doc-row').remove();
			reindexCategoryRows();
		});
	});
})(jQuery);
