(function() {
    var TRUNCATE_AT = 3;

    function widgetLang(fromEl) {
        var root = fromEl ? jQuery(fromEl).closest('#conv-multi-assignee, #multi-assign-create') : jQuery();
        if (!root.length) {
            root = jQuery('#conv-multi-assignee, #multi-assign-create').first();
        }
        return {
            none: root.attr('data-lang-none') || 'None',
            assignees: root.attr('data-lang-assignees') || 'assignees',
            max: root.attr('data-lang-max') || 'Maximum of :max additional assignees'
        };
    }

    function formatLabel(names, lang) {
        if (!names.length) return lang.none;
        if (names.length <= TRUNCATE_AT) return names.join(', ');
        return names.length + ' ' + lang.assignees;
    }

    // --- Conversation view: toggle via AJAX ---
    var dropdown = document.querySelector('.multi-assign-dropdown');
    if (dropdown) {
        dropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });

        jQuery('.multi-assign-toggle').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            var link = jQuery(this);
            var userId = link.data('user_id');
            var conversationId = dropdown.getAttribute('data-conversation-id');
            var icon = link.find('.glyphicon');

            fsAjax(
                {
                    user_id: userId,
                    conversation_id: conversationId
                },
                laroute.route('multiassign.toggle'),
                function(response) {
                    if (response.status === 'success') {
                        if (icon.hasClass('glyphicon-unchecked')) {
                            icon.removeClass('glyphicon-unchecked').addClass('glyphicon-check');
                        } else {
                            icon.removeClass('glyphicon-check').addClass('glyphicon-unchecked');
                        }
                        var names = response.assignees.map(function(a) { return a.name; });
                        var label = formatLabel(names, widgetLang(dropdown));
                        jQuery('.multi-assign-label').text(label);
                        jQuery('#conv-multi-assignee').attr('data-original-title', response.label);
                    } else if (response.msg) {
                        showFloatingAlert('error', response.msg);
                    }
                },
                true
            );
        });

        // Clear all button
        jQuery('.multi-assign-clear-all').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            var checked = jQuery('.multi-assign-dropdown .glyphicon-check');
            if (!checked.length) return;

            var conversationId = dropdown.getAttribute('data-conversation-id');

            fsAjax(
                {
                    conversation_id: conversationId
                },
                laroute.route('multiassign.clear'),
                function(response) {
                    if (typeof(response.status) != 'undefined' && response.status === 'success') {
                        jQuery('.multi-assign-dropdown .glyphicon-check')
                            .removeClass('glyphicon-check')
                            .addClass('glyphicon-unchecked');
                        var noneLabel = widgetLang(dropdown).none;
                        jQuery('.multi-assign-label').text(noneLabel);
                        jQuery('#conv-multi-assignee').attr('data-original-title', noneLabel);
                    } else if (response.msg) {
                        showFloatingAlert('error', response.msg);
                    }
                },
                true
            );
        });
    }

    // --- Create form: checkbox dropdown with hidden inputs ---
    var createDropdown = document.querySelector('.multi-assign-create-dropdown');
    if (createDropdown) {
        var maxAssignees = parseInt(jQuery('#multi-assign-create').data('max') || 0);

        createDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });

        jQuery('.multi-assign-create-toggle').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            var link = jQuery(this);
            var icon = link.find('.glyphicon');

            if (icon.hasClass('glyphicon-unchecked')) {
                if (maxAssignees > 0) {
                    var currentCount = jQuery('.multi-assign-create-dropdown .glyphicon-check').length;
                    if (currentCount >= maxAssignees) {
                        var maxLabel = widgetLang(createDropdown).max.replace(':max', maxAssignees);
                        showFloatingAlert('error', maxLabel);
                        return;
                    }
                }
                icon.removeClass('glyphicon-unchecked').addClass('glyphicon-check');
            } else {
                icon.removeClass('glyphicon-check').addClass('glyphicon-unchecked');
            }

            syncCreateInputs();
        });

        // Clear all button
        jQuery('.multi-assign-create-clear-all').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            jQuery('.multi-assign-create-dropdown .glyphicon-check')
                .removeClass('glyphicon-check')
                .addClass('glyphicon-unchecked');

            syncCreateInputs();
        });

        // Sync with primary assignee dropdown: uncheck if selected as primary
        jQuery('select[name="user_id"]').on('change', function() {
            var primaryId = jQuery(this).val();
            jQuery('.multi-assign-create-toggle').each(function() {
                var link = jQuery(this);
                if (String(link.data('user_id')) === String(primaryId)) {
                    link.find('.glyphicon-check')
                        .removeClass('glyphicon-check')
                        .addClass('glyphicon-unchecked');
                }
            });
            syncCreateInputs();
        });
    }

    function syncCreateInputs() {
        var container = jQuery('#multi-assign-create-inputs');
        var label = jQuery('.multi-assign-create-label');
        container.empty();

        var names = [];
        jQuery('.multi-assign-create-toggle').each(function() {
            var link = jQuery(this);
            if (link.find('.glyphicon-check').length) {
                var userId = link.data('user_id');
                var userName = link.data('user_name');
                container.append('<input type="hidden" name="multi_assign_user_ids[]" value="' + userId + '">');
                names.push(userName);
            }
        });

        label.text(formatLabel(names, widgetLang(createDropdown)));

        if (names.length) {
            jQuery('.multi-assign-clear-item, .multi-assign-clear-divider').show();
        } else {
            jQuery('.multi-assign-clear-item, .multi-assign-clear-divider').hide();
        }
    }
})();
