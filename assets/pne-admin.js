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
});
