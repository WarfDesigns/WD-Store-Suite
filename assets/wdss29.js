(function($){
  function post(action, data){ data=data||{}; data.action=action; return $.post(WDSS29.ajax_url, data); }
  function map($wrap){ try{ return JSON.parse($wrap.find('.wd-size-map').text()||'{}'); }catch(e){ return {}; } }
  function computePrice($wrap){
    var base = parseFloat($wrap.data('base'))||0;
    var m = map($wrap);
    var size = parseInt($wrap.find('.wd-size').val(),10)||0;
    var delta = m.hasOwnProperty(size) ? parseFloat(m[size])||0 : 0;
    var price = base + delta;
    if ($wrap.find('.wd-front3').length && $wrap.find('.wd-front3').is(':checked')) price += parseFloat($wrap.data('front3')||0);
    if ($wrap.find('.wd-train12').length && $wrap.find('.wd-train12').is(':checked')) price += parseFloat($wrap.data('train12')||0);
    if ($wrap.find('.wd-3134').length && $wrap.find('.wd-3134').is(':checked')) price += parseFloat($wrap.data('3134')||0);
    if ($wrap.find('.wd-rush').length && $wrap.find('.wd-rush').is(':checked')) price += parseFloat($wrap.data('rush')||0);
    $wrap.find('.wd-price-val').text((price||0).toFixed(2));
    return {price: price, size: size};
  }
  $(document).on('change','.wdss29-controls input, .wdss29-controls select', function(){ computePrice($(this).closest('.wdss29-controls')); });
  $(document).ready(function(){ $('.wdss29-controls').each(function(){ computePrice($(this)); }); });

  $(document).on('click','.wdss29-add', function(e){
    e.preventDefault();
    var $wrap = $(this).closest('.wdss29-controls');
    var $title = $wrap.closest('.wdss29-product, .wdss29-single-buybox').prevAll('h1,h2,h3').first();
    var name = $title.length ? $title.text() : document.title;
    var pid = parseInt($wrap.data('product'),10);
    var color = $wrap.find('.wd-color').val() || '';
    var size = parseInt($wrap.find('.wd-size').val(),10)||0;
    post('wd_add_to_cart', {
      nonce: WDSS29.nonce, product_id: pid, name: name + (color?(' - '+color):''),
      size: size, color: color,
      front3: $wrap.find('.wd-front3').is(':checked') ? 1 : 0,
      train12: $wrap.find('.wd-train12').is(':checked') ? 1 : 0,
      inch3134: $wrap.find('.wd-3134').is(':checked') ? 1 : 0,
      rush: $wrap.find('.wd-rush').is(':checked') ? 1 : 0
    }).done(function(res){
      if(res.success){ alert('Added to cart at $'+(res.data.price||0).toFixed(2)); }
      else{ alert((res.data&&res.data.message)||'Error'); }
    });
  });

  function recalcTotals(){
    var subtotal = 0;
    $('.wd-table tbody tr').each(function(){
      var line = $(this).find('.wd-line').text().replace('$','');
      subtotal += parseFloat(line||0);
    });
    var taxRate = parseFloat($('.wd-totals').data('tax')||0) / 100.0;
    var feeRate = parseFloat($('.wd-totals').data('stripe')||0) / 100.0;
    var tax = Math.round((subtotal * taxRate)*100)/100;
    var fee = Math.round(((subtotal + tax) * feeRate)*100)/100;
    var total = subtotal + tax + fee;
    $('.wd-subtotal').text(subtotal.toFixed(2));
    $('.wd-tax').text(tax.toFixed(2));
    $('.wd-fee').text(fee.toFixed(2));
    $('.wd-total').text(total.toFixed(2));
  }

  $(document).on('change', '.wd-qty', function(){
    var $tr = $(this).closest('tr');
    var key = $tr.data('key');
    var qty = Math.max(1, parseInt($(this).val(),10)||1);
    post('wd_update_qty', { nonce: WDSS29.nonce, key: key, qty: qty }).done(function(res){
      if(res.success){
        var price = parseFloat($tr.find('td:nth-child(3)').text().replace('$','')||0);
        var line = price * qty;
        $tr.find('.wd-line').text('$'+line.toFixed(2));
        recalcTotals();
      }
    });
  });

  $(document).on('click','.wd-remove', function(e){
    e.preventDefault();
    var $tr = $(this).closest('tr');
    var key = $tr.data('key');
    post('wd_remove_from_cart', { nonce: WDSS29.nonce, key: key }).done(function(res){
      if(res.success){
        $tr.remove();
        recalcTotals();
      }
    });
  });

  $(document).on('click','.wd-pay-stripe', function(e){
    e.preventDefault();
    post('wd_create_stripe_session', { nonce: WDSS29.nonce }).done(function(res){
      if(res && res.success && res.data && res.data.url){
        window.location.href = res.data.url;
      }else{
        alert((res && res.data && res.data.message) ? res.data.message : 'Stripe error');
      }
    });
  });

  // Lightbox
  function openLightbox(url){
    var $lb = $('#wdss29-lightbox');
    if($lb.length===0){
      $('body').append('<div id="wdss29-lightbox" class="wdss29-lightbox"><span class="wdss29-close">&times;</span><img class="wdss29-lightbox-img" src="" alt=""/></div>');
      $lb = $('#wdss29-lightbox');
    }
    $lb.find('img').attr('src', url);
    $lb.css('display','flex');
  }
  $(document).on('click','.wdss29-lightboxable', function(e){
    e.preventDefault();
    var url = $(this).data('full') || $(this).attr('href');
    if(url){ openLightbox(url); }
  });
  $(document).on('click','.wdss29-close, #wdss29-lightbox', function(e){
    if(e.target.id==='wdss29-lightbox' || $(e.target).hasClass('wdss29-close')){
      $('#wdss29-lightbox').hide();
    }
  });
})(jQuery);
