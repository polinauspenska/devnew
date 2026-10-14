// Shared handler for every enquiry form on the site (homepage "Express Your
// Interest", Contact, Book a Meeting, and both Broker Partner forms). They all
// carry class="interest-form", so one generic handler covers them.
//
// Submissions POST to /send.php, which emails them to info@afmdevelopments.com.
// This replaced a mailto: link that opened the visitor's own mail client --
// which produced nothing at all for anyone on webmail or mobile, and lost the
// lead whenever they abandoned the half-written message.
//
// Field labels are read from each input's own <label> so the emailed body stays
// readable, and so a form can gain or lose fields without touching this file.
(function(){ try {
  var forms = document.querySelectorAll('form.interest-form');
  if (!forms.length) return;

  function setStatus(el, msg, state){
    if (!el) return;
    el.textContent = msg;
    el.setAttribute('data-state', state || '');
    // Announce success/failure to screen readers, which otherwise get no
    // indication that anything happened after pressing submit.
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
  }

  forms.forEach(function(form){
    // A field real visitors never see. Bots fill every input they find, so
    // anything arriving with this set is discarded server-side.
    var trap = document.createElement('input');
    trap.type = 'text';
    trap.name = '_trap';
    trap.tabIndex = -1;
    trap.autocomplete = 'off';
    trap.setAttribute('aria-hidden', 'true');
    trap.style.cssText = 'position:absolute;left:-9999px;width:1px;height:1px;opacity:0;';
    form.appendChild(trap);

    var status = form.querySelector('.interest-status') ||
                 document.getElementById('interestStatus');
    var submitBtn = form.querySelector('button[type="submit"], .interest-submit');
    var submitLabel = submitBtn ? submitBtn.textContent : '';

    form.addEventListener('submit', function(e){
      e.preventDefault();

      // Let the browser's own validation surface missing/!invalid fields first.
      if (typeof form.checkValidity === 'function' && !form.checkValidity()){
        if (typeof form.reportValidity === 'function') form.reportValidity();
        return;
      }

      var fields = [];
      var els = form.querySelectorAll('input[name], select[name], textarea[name]');
      for (var i = 0; i < els.length; i++){
        var el = els[i];
        if (el.name === '_trap') continue;
        var labelEl = el.id ? form.querySelector('label[for="' + el.id + '"]') : null;
        var label = labelEl ? labelEl.textContent.trim() : el.name;
        fields.push({ label: label, value: (el.value || '').trim() });
      }

      if (submitBtn){
        submitBtn.disabled = true;
        submitBtn.textContent = 'Sending…';
      }
      setStatus(status, 'Sending your enquiry…', 'pending');

      fetch('/send.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          fields: fields,
          subject: form.getAttribute('data-subject') || 'Website Enquiry',
          page: window.location.href,
          _trap: trap.value
        })
      })
      .then(function(res){
        return res.json().catch(function(){ return { ok: false, error: 'Unexpected server response.' }; });
      })
      .then(function(data){
        if (data && data.ok){
          form.reset();
          setStatus(status, 'Thank you — your enquiry has been sent. Our team will be in touch shortly.', 'success');
          if (submitBtn){ submitBtn.textContent = 'Sent'; }
        } else {
          setStatus(status, (data && data.error) || 'Something went wrong. Please email info@afmdevelopments.com directly.', 'error');
          if (submitBtn){ submitBtn.disabled = false; submitBtn.textContent = submitLabel; }
        }
      })
      .catch(function(){
        setStatus(status, 'Could not reach the server. Please check your connection, or email info@afmdevelopments.com directly.', 'error');
        if (submitBtn){ submitBtn.disabled = false; submitBtn.textContent = submitLabel; }
      });
    });
  });
} catch(e){ console.error('AFM interest form error:', e); } })();
