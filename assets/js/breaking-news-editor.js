(function () {
    'use strict';

    var COLOR_PREFIX = {
        gold: '|cFFFFCC00',
        red: '|cFFFF0000',
        green: '|cFF00FF00',
        blue: '|cFF0088FF',
        yellow: '|cFFFFFF00',
        gray: '|cFF888888',
        orange: '|cFFFF8800'
    };

    function escapeHtml(text) {
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatInline(text) {
        var lines = text.split('\n');
        return lines.map(function (line) {
            return escapeHtml(line);
        }).join('<br/>');
    }

    function compileBodyHtml(bodySource) {
        bodySource = (bodySource || '').trim();
        if (!bodySource) {
            return '<html><body></body></html>';
        }
        var paragraphs = bodySource.split(/\n\s*\n/);
        var blocks = ['<html><body>'];
        paragraphs.forEach(function (para) {
            para = para.trim();
            if (!para) {
                return;
            }
            var heading = para.match(/^(#{1,3})\s+([\s\S]*)$/);
            if (heading) {
                var level = Math.min(3, heading[1].length);
                blocks.push('<' + 'h' + level + '>' + formatInline(heading[2].trim()) + '</' + 'h' + level + '>');
                return;
            }
            blocks.push('<p>' + formatInline(para) + '</p>');
        });
        blocks.push('</body></html>');
        return blocks.join('');
    }

    function colorCodesToSpans(html) {
        return html.replace(/\|c([0-9A-Fa-f]{8})([\s\S]*?)\|r/g, function (_m, argb, inner) {
            var hex = argb.length >= 8 ? argb.slice(2) : argb;
            var color = '#' + hex;
            return '<span style="color:' + color + '">' + inner + '</span>';
        });
    }

    function buildPreview(title, bodySource) {
        title = (title || '').trim();
        var html = '';
        if (title) {
            html += '<h1 class="bn-preview-h1">' + escapeHtml(title) + '</h1>';
        }
        var doc = compileBodyHtml(bodySource);
        var bodyMatch = doc.match(/<body>([\s\S]*)<\/body>/i);
        if (bodyMatch) {
            html += colorCodesToSpans(bodyMatch[1]);
        }
        return html || '<p class="text-gray-500">—</p>';
    }

    function wrapSelection(textarea, before, after) {
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        var value = textarea.value;
        var selected = value.slice(start, end);
        var replacement = before + (selected || 'text') + after;
        textarea.value = value.slice(0, start) + replacement + value.slice(end);
        var cursor = start + replacement.length;
        textarea.focus();
        textarea.setSelectionRange(cursor, cursor);
    }

    function insertAtLineStart(textarea, prefix) {
        var value = textarea.value;
        var start = textarea.selectionStart;
        var lineStart = value.lastIndexOf('\n', start - 1) + 1;
        textarea.value = value.slice(0, lineStart) + prefix + value.slice(lineStart);
        textarea.focus();
        textarea.setSelectionRange(start + prefix.length, start + prefix.length);
    }

    function initEditor(root) {
        var titleEl = root.querySelector('[data-bn-title]');
        var bodyEl = root.querySelector('[data-bn-body]');
        var previewEl = root.querySelector('[data-bn-preview]');
        if (!bodyEl || !previewEl) {
            return;
        }

        function refreshPreview() {
            previewEl.innerHTML = buildPreview(titleEl ? titleEl.value : '', bodyEl.value);
        }

        root.querySelectorAll('[data-bn-color]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var key = btn.getAttribute('data-bn-color');
                var prefix = COLOR_PREFIX[key];
                if (!prefix) {
                    return;
                }
                wrapSelection(bodyEl, prefix, '|r');
                refreshPreview();
            });
        });

        var resetBtn = root.querySelector('[data-bn-reset-color]');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                wrapSelection(bodyEl, '', '|r');
                refreshPreview();
            });
        }

        root.querySelectorAll('[data-bn-heading]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var level = btn.getAttribute('data-bn-heading') || '1';
                insertAtLineStart(bodyEl, '#'.repeat(parseInt(level, 10)) + ' ');
                refreshPreview();
            });
        });

        var paraBtn = root.querySelector('[data-bn-paragraph-break]');
        if (paraBtn) {
            paraBtn.addEventListener('click', function () {
                wrapSelection(bodyEl, '\n\n', '');
                refreshPreview();
            });
        }

        bodyEl.addEventListener('input', refreshPreview);
        if (titleEl) {
            titleEl.addEventListener('input', refreshPreview);
        }
        refreshPreview();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-breaking-news-editor]').forEach(initEditor);
    });
})();
