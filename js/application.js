(function($) {
	$(function() {
		$('a.dismiss-comment').click(function (e) {
			e.preventDefault();
			$.post(
                $(this).attr('href'),
                {
                    action      : 'dismiss_comment',
                    comment_id  : $(this).data('comment_id'),
                    _wpnonce    : $(this).data('nonce'),
                },
                function(response) {    
                    if (response.type === 'success') {
                    	$('tr#comment-' + response.comment_id).fadeOut('fast', function () {
                    		$(this).remove();
                    	});
                    	$('li.dismissed_comment .pending-count').text(parseInt($('li.dismissed_comment .pending-count').text()) + 1);
                    }
                },
                'json'
            );
		});

		$('a.undismiss-comment').click(function (e) {
			e.preventDefault();
			$.post(
                $(this).attr('href'),
                {
                    action      : 'undismiss_comment',
                    comment_id  : $(this).data('comment_id'),
                    _wpnonce    : $(this).data('nonce'),
                },
                function(response) {    
                    if (response.type === 'success') {
                    	$('tr#comment-' + response.comment_id).fadeOut('fast', function () {
                    		$(this).remove();
                    	});
                    	$('li.dismissed_comment .pending-count').text(parseInt($('li.dismissed_comment .pending-count').text()) - 1);
                    }
                },
                'json'
            );
		});
	});
})(jQuery);