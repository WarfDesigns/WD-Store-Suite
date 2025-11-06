(function($){
  $(document).on('click', '#wdss-add-rule', function(){
    var $last = $('#wdss-rules-table tbody tr.wdss-rule:last');
    var $clone = $last.clone(true);
    var idx = $('#wdss-rules-table tbody tr.wdss-rule').length;

    $clone.find('input, select, textarea').each(function(){
      var name = $(this).attr('name');
      if (!name) return;
      name = name.replace(/\[\d+\]/, '['+idx+']');
      $(this).attr('name', name);
      if ($(this).is(':checkbox')) {
        $(this).prop('checked', true);
      } else {
        $(this).val('');
      }
    });

    $clone.appendTo('#wdss-rules-table tbody');
  });

  $(document).on('click', '.wdss-remove-rule', function(){
    var $rows = $('#wdss-rules-table tbody tr.wdss-rule');
    if ($rows.length <= 1) { alert('At least one rule row must remain.'); return; }
    $(this).closest('tr').remove();
  });

  $(document).on('change', 'select.wdss-recipient', function(){
    var $td = $(this).closest('td');
    if ($(this).val() === 'custom') {
      $td.find('.wdss-recipient-email').show();
    } else {
      $td.find('.wdss-recipient-email').hide();
    }
  });

  $(document).on('click', '#wdss-test-send', function(e){
    e.preventDefault();
    var tpl = parseInt($('#wdss-test-template-id').val(), 10);
    var email = $('#wdss-test-email').val();
    if (!tpl || !email) { alert('Provide both template ID and email'); return; }
    $.post(ajaxurl, { action: 'wdss_test_email', template_id: tpl, email: email }, function(resp){
      if (resp && resp.success) alert('Test email sent.');
      else alert('Failed: ' + (resp && resp.data && resp.data.message ? resp.data.message : 'Unknown error'));
    });
  });
})(jQuery);
