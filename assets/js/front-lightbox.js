(function(){
  function qsa(sel, ctx){ return Array.prototype.slice.call((ctx||document).querySelectorAll(sel)); }

  var overlay, img, btnPrev, btnNext, btnClose, currentGroup = null, nodes = [], index = -1;

  function buildOverlay(){
    overlay = document.createElement('div');
    overlay.className = 'wdss29-lightbox-overlay';
    overlay.innerHTML = '' +
      '<div class="wdss29-lightbox-stage">' +
        '<button class="wdss29-lightbox-close" aria-label="Close">&times;</button>' +
        '<button class="wdss29-lightbox-prev" aria-label="Previous">&#10094;</button>' +
        '<img class="wdss29-lightbox-img" alt="">' +
        '<button class="wdss29-lightbox-next" aria-label="Next">&#10095;</button>' +
      '</div>';
    document.body.appendChild(overlay);
    img      = overlay.querySelector('.wdss29-lightbox-img');
    btnPrev  = overlay.querySelector('.wdss29-lightbox-prev');
    btnNext  = overlay.querySelector('.wdss29-lightbox-next');
    btnClose = overlay.querySelector('.wdss29-lightbox-close');

    overlay.addEventListener('click', function(e){
      if (e.target === overlay) close();
    });
    btnClose.addEventListener('click', close);
    btnPrev.addEventListener('click', function(e){ e.stopPropagation(); show(index-1); });
    btnNext.addEventListener('click', function(e){ e.stopPropagation(); show(index+1); });

    document.addEventListener('keydown', function(e){
      if (!overlay.classList.contains('open')) return;
      if (e.key === 'Escape') close();
      if (e.key === 'ArrowLeft') show(index-1);
      if (e.key === 'ArrowRight') show(index+1);
    });
  }

  function open(group, href){
    if (!overlay) buildOverlay();
    overlay.classList.add('open');
    currentGroup = group;
    nodes = qsa('.wdss29-lightbox[data-group="'+group+'"]');
    index = nodes.findIndex(function(n){ return n.getAttribute('href')===href; });
    if (index < 0) index = 0;
    show(index);
  }

  function show(i){
    if (!nodes.length) return;
    if (i < 0) i = nodes.length - 1;
    if (i >= nodes.length) i = 0;
    index = i;
    var href = nodes[index].getAttribute('href');
    img.src = href;
  }

  function close(){
    overlay.classList.remove('open');
    currentGroup = null;
    nodes = [];
    index = -1;
    img.src = '';
  }

  // delegate clicks
  document.addEventListener('click', function(e){
    var a = e.target.closest('a.wdss29-lightbox');
    if (!a) return;
    e.preventDefault();
    var group = a.getAttribute('data-group') || 'wdss29';
    open(group, a.getAttribute('href'));
  });
})();
