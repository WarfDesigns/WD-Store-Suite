(function($){
  $(document).on('click', '#wdss29-send-test', function(e){
    e.preventDefault();
    const postId = $(this).data('post');
    const to = prompt('Send test to which email address?', window?.ajaxurlEmail || '');
    if(!to) return;

    const $status = $('#wdss29-test-status').text('Sending…');

    $.post(WDSS_EMAIL_TPL.ajaxurl, {
      action: 'wdss29_send_test_email',
      nonce: WDSS_EMAIL_TPL.nonce,
      postId: WDSS_EMAIL_TPL.postId || postId,
      to: to
    }).done(function(resp){
      if(resp && resp.success){
        $status.text(resp.data && resp.data.message ? resp.data.message : 'Sent!');
      } else {
        $status.text(resp && resp.data && resp.data.message ? resp.data.message : 'Failed.');
      }
    }).fail(function(){
      $status.text('Failed.');
    });
  });
})(jQuery);
