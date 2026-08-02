/* =============================================================================
   Progressive enhancement only. Everything here is optional polish; the site
   works without JavaScript.

   Handlers are attached with addEventListener rather than written as onclick=""
   attributes in the markup, because the Content-Security-Policy set in
   bootstrap.php uses script-src 'self' with no 'unsafe-inline'. An inline
   handler would be blocked and would silently never fire.
   ============================================================================= */

/* Lets CSS show controls that only work when JavaScript is running. Set
   immediately rather than on DOMContentLoaded so the dismiss button is never
   briefly visible in a state where clicking it would do nothing. */
document.documentElement.classList.add('js');

document.addEventListener('DOMContentLoaded', function () {

    // -------------------------------------------------------------------------
    // Confirm before deleting
    // -------------------------------------------------------------------------

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    // -------------------------------------------------------------------------
    // Dismissible flash messages
    // -------------------------------------------------------------------------

    /**
     * Fade a flash out, then remove it from the document.
     *
     * The node is removed rather than just hidden so it leaves the accessibility
     * tree too -- a visually faded message that a screen reader still reads is
     * not dismissed in any meaningful sense.
     */
    function dismiss(flash) {
        if (flash.dataset.dismissing === 'true') {
            return;                       // already on its way out
        }
        flash.dataset.dismissing = 'true';
        flash.classList.add('is-dismissing');

        var done = function () {
            var container = flash.parentElement;
            flash.remove();

            // Drop the wrapper too once it is empty, so its margin does not
            // leave a gap where the messages used to be.
            if (container && container.classList.contains('flashes')
                && container.children.length === 0) {
                container.remove();
            }
        };

        // transitionend does not fire when the transition is instant, which is
        // exactly what the prefers-reduced-motion rule in site.css does. The
        // timeout is the fallback that keeps removal working in that case.
        flash.addEventListener('transitionend', done, { once: true });
        window.setTimeout(done, 400);
    }

    document.querySelectorAll('.flash').forEach(function (flash) {
        var closeButton = flash.querySelector('.flash__close');

        if (closeButton) {
            closeButton.addEventListener('click', function () {
                dismiss(flash);
            });
        }

        var delay = parseInt(flash.dataset.dismissAfter || '0', 10);

        if (!delay) {
            return;                       // errors stay until dismissed by hand
        }

        var timer = window.setTimeout(function () {
            dismiss(flash);
        }, delay);

        // Hold the message while it is being read or interacted with. Someone
        // hovering or tabbing to it is plainly still using it, and having it
        // vanish under the pointer on the way to the close button is annoying.
        var hold = function () {
            window.clearTimeout(timer);
        };
        var resume = function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                dismiss(flash);
            }, delay);
        };

        flash.addEventListener('mouseenter', hold);
        flash.addEventListener('focusin', hold);
        flash.addEventListener('mouseleave', resume);
        flash.addEventListener('focusout', resume);
    });
});
