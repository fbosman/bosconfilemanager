/* Copyright (C) 2026 Boscon
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * BosConfilemanager: directory tree, list filter, drag and drop upload and ACE editor.
 */

(function ($) {
	'use strict';

	/* Directory tree: expand/collapse, children are loaded on demand */
	function buildNode(item) {
		var li = $('<li class="bcfm-node"></li>').attr('data-rel', item.rel);
		if (!item.hassub) {
			li.addClass('bcfm-leaf');
		}
		li.append('<span class="bcfm-toggle"></span>');
		var a = $('<a></a>').attr('href', item.url);
		a.append('<span class="fas fa-folder pictofixedwidth bcfm-diricon"></span>');
		a.append(document.createTextNode(item.name));
		li.append(a);
		return li;
	}

	function initTree() {
		var tree = $('.bcfm-tree');
		if (!tree.length) {
			return;
		}
		var ajaxurl = tree.data('ajaxurl');
		tree.on('click', '.bcfm-toggle', function () {
			var li = $(this).closest('li.bcfm-node');
			if (li.hasClass('bcfm-leaf')) {
				return;
			}
			if (li.hasClass('bcfm-open')) {
				li.removeClass('bcfm-open');
				return;
			}
			li.addClass('bcfm-open');
			if (li.hasClass('bcfm-loaded') || li.data('rel') === '') {
				return;
			}
			li.addClass('bcfm-loading');
			$.getJSON(ajaxurl, {dir: li.attr('data-rel')}).done(function (items) {
				var ul = $('<ul class="bcfm-treelist"></ul>');
				$.each(items, function (i, item) {
					ul.append(buildNode(item));
				});
				li.children('ul').remove();
				li.append(ul).addClass('bcfm-loaded');
				if (!items.length) {
					li.addClass('bcfm-leaf');
				}
			}).fail(function () {
				li.removeClass('bcfm-open');
			}).always(function () {
				li.removeClass('bcfm-loading');
			});
		});
		var current = tree.find('a.bcfm-current');
		if (current.length && current[0].scrollIntoView) {
			current[0].scrollIntoView({block: 'center'});
		}
	}

	/* Filter rows of the file list by name */
	function initFilter() {
		$('.bcfm-filterinput').on('input', function () {
			var q = $(this).val().toLowerCase();
			$('.bcfm-list tr.bcfm-row').each(function () {
				$(this).toggle(q === '' || String($(this).data('name')).indexOf(q) !== -1);
			});
		});
	}

	/* Drop files anywhere on the file list to upload them */
	function initDropzone() {
		var form = $('form.bcfm-upload');
		if (!form.length) {
			return;
		}
		var input = form.find('.bcfm-fileinput')[0];
		var zone = $('.bcfm-main');
		var depth = 0;
		zone.on('dragenter', function (e) {
			if (e.originalEvent.dataTransfer && $.inArray('Files', e.originalEvent.dataTransfer.types) !== -1) {
				depth++;
				zone.addClass('bcfm-dragover');
				e.preventDefault();
			}
		}).on('dragover', function (e) {
			e.preventDefault();
		}).on('dragleave', function () {
			depth = Math.max(0, depth - 1);
			if (!depth) {
				zone.removeClass('bcfm-dragover');
			}
		}).on('drop', function (e) {
			e.preventDefault();
			depth = 0;
			zone.removeClass('bcfm-dragover');
			var files = e.originalEvent.dataTransfer ? e.originalEvent.dataTransfer.files : null;
			if (files && files.length) {
				input.files = files;
				form.trigger('submit');
			}
		});
	}

	/* ACE editor on top of the textarea; the textarea stays the posted field and the fallback */
	function initEditor() {
		var holder = $('#bcfm-editor');
		var textarea = $('#bcfm-content');
		if (!holder.length || !textarea.length || typeof window.ace === 'undefined' || !holder.data('acebase')) {
			return;
		}
		window.ace.config.set('basePath', holder.data('acebase'));
		var readonly = String(holder.data('readonly')) === '1';
		holder.removeClass('hideobject');
		textarea.addClass('hideobject');

		var editor = window.ace.edit('bcfm-editor');
		editor.setTheme('ace/theme/' + holder.data('theme'));
		editor.session.setMode('ace/mode/' + holder.data('mode'));
		editor.session.setUseWorker(false);
		editor.session.setValue(textarea.val());
		editor.setOptions({
			readOnly: readonly,
			showPrintMargin: false,
			fontSize: '13px',
			useSoftTabs: false,
			tabSize: 4
		});
		if (readonly) {
			editor.renderer.$cursorLayer.element.style.display = 'none';
		}

		var form = textarea.closest('form');
		var dirty = false;
		editor.session.on('change', function () {
			dirty = true;
		});
		form.on('submit', function () {
			textarea.val(editor.session.getValue());
			dirty = false;
		});
		if (!readonly) {
			editor.commands.addCommand({
				name: 'save',
				bindKey: {win: 'Ctrl-S', mac: 'Command-S'},
				exec: function () {
					form.trigger('submit');
				}
			});
			$(window).on('beforeunload', function () {
				if (dirty) {
					return true;
				}
			});
			editor.focus();
		}
	}

	$(function () {
		initTree();
		initFilter();
		initDropzone();
		initEditor();
	});
})(jQuery);
