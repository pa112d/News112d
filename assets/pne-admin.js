jQuery(function($) {
    // Select PNG button
    $('#pne_select_png').on('click', function(e) {
        e.preventDefault();
        
        var frame = wp.media({
            title: 'Select PNG Image',
            button: {
                text: 'Use this PNG'
            },
            multiple: false,
            library: {
                type: 'image'
            }
        });
        
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#pne_png_id').val(attachment.id);
            $('#pne_png_preview').text(attachment.filename);
        });
        
        frame.open();
    });
    
    // Select PDF button
    $('#pne_select_pdf').on('click', function(e) {
        e.preventDefault();
        
        var frame = wp.media({
            title: 'Select PDF File',
            button: {
                text: 'Use this PDF'
            },
            multiple: false,
            library: {
                type: 'application/pdf'
            }
        });
        
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#pne_pdf_id').val(attachment.id);
            $('#pne_pdf_preview').text(attachment.filename);
        });
        
        frame.open();
    });

    // Save-then-send-test: intercept the Send Test link, save the post first
    // so that any unsaved field edits are persisted before the test is sent.
    $('#pne-send-test-btn').on('click', function(e) {
        e.preventDefault();
        var testUrl = $(this).attr('href');
        var $saveBtn = $('#publish, #save-post').first();
        if ($saveBtn.length) {
            // Store the pending test URL keyed to the current post so that after
            // WordPress redirects back to the edit page (with ?message=N) we can
            // automatically navigate to the test URL.
            var urlParams = new URLSearchParams(window.location.search);
            var postId = urlParams.get('post') || '';
            sessionStorage.setItem('pne_pending_test_url', testUrl);
            sessionStorage.setItem('pne_pending_test_post', postId);
            $saveBtn.trigger('click');
        } else {
            // No save button found – navigate directly.
            window.location.href = testUrl;
        }
    });

    // After WordPress saves the post it redirects back to the edit page with a
    // ?message=N parameter.  If we stored a pending test URL for this post, pick
    // it up and navigate there now.
    (function() {
        var pendingUrl  = sessionStorage.getItem('pne_pending_test_url');
        var pendingPost = sessionStorage.getItem('pne_pending_test_post');
        if (!pendingUrl || !pendingPost) return;

        var urlParams = new URLSearchParams(window.location.search);
        var currentPost = urlParams.get('post') || '';

        if (urlParams.has('message') && currentPost === pendingPost) {
            sessionStorage.removeItem('pne_pending_test_url');
            sessionStorage.removeItem('pne_pending_test_post');
            window.location.href = pendingUrl;
        }
    }());
});
