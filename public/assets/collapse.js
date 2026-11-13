/*
 * Collapse and expand reply branches (optional challenge, section 9).
 *
 * The server already draws every reply and also draws one button for each
 * comment that has replies. The buttons start with the "hidden" attribute, so
 * without JavaScript the thread stays fully open and no dead control appears.
 * This file only shows the buttons and wires them up.
 *
 * Nothing from a comment is read or written here. The script uses only the
 * button's aria-controls value (an ID such as "replies-5") to find the branch.
 */
(function () {
    'use strict';

    function setup(button) {
        var branch = document.getElementById(button.getAttribute('aria-controls'));
        var label = button.querySelector('.toggle-label');

        // If the branch cannot be found, leave the button hidden instead of showing a dead control.
        if (!branch || !label) {
            return;
        }

        button.addEventListener('click', function () {
            var isOpen = button.getAttribute('aria-expanded') === 'true';

            branch.hidden = isOpen;
            button.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            label.textContent = isOpen ? 'Show replies' : 'Hide replies';
        });

        button.hidden = false;
    }

    function start() {
        var buttons = document.querySelectorAll('button.toggle-replies');
        for (var i = 0; i < buttons.length; i++) {
            setup(buttons[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
