<?php
// includes/footer.php
// Minimal footer to close the layout started in includes/header.php
?>
     <!-- /container opened in header.php -->

    <footer class="footer mt-auto py-4 bg-light">
      <div class="container d-flex justify-content-between align-items-center">
        <div class="small text-muted">© <?= date('Y') ?> Fleur de Ruby Flower Collection</div>
      </div>
    </footer>

    <!-- Bootstrap JS (CDN). Remove if your project already loads bootstrap JS. -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="" crossorigin="anonymous"></script>

    <!-- Small UI helper script -->
    <script>
    (function(){
      // Close dropdowns on navigation (mobile UX)
      document.querySelectorAll('.navbar-nav .nav-link').forEach(function(el){
        el.addEventListener('click', function(){
          var bsCollapse = document.querySelector('.navbar-collapse');
          if (bsCollapse && bsCollapse.classList.contains('show')) {
            var collapse = bootstrap.Collapse.getInstance(bsCollapse) || new bootstrap.Collapse(bsCollapse);
            collapse.hide();
          }
        });
      });
    })();
    </script>

    <!-- Add-to-cart AJAX behavior (progressive enhancement)
         - Intercepts forms with class 'add-to-cart-form' and sends POST via fetch
         - Updates cart badge (.cart-badge) when successful
         - Shows a small transient message on success/failure
         - Falls back to normal submit if fetch or JS unavailable
    -->
    <script>
    (function(){
      // Simple helper to show a transient toast-like message
      function showFlash(text, type) {
        var el = document.createElement('div');
        el.className = 'alert alert-' + (type || 'success') + ' toast-feedback';
        el.style.position = 'fixed';
        el.style.right = '18px';
        el.style.bottom = '18px';
        el.style.zIndex = 13000;
        el.style.minWidth = '220px';
        el.style.boxShadow = '0 10px 30px rgba(0,0,0,0.12)';
        el.innerText = text;
        document.body.appendChild(el);
        setTimeout(function(){ el.style.opacity = '0'; el.style.transform = 'translateY(6px)'; }, 2200);
        setTimeout(function(){ el.remove(); }, 2600);
      }

      // Update cart badge (create if absent)
      function updateCartBadge(count) {
        var existing = document.querySelector('.cart-badge');
        if (existing) {
          existing.textContent = count;
          if (count <= 0) existing.remove();
          return;
        }
        // find cart link in navbar
        var cartLink = document.querySelector('a[href="cart.php"]');
        if (!cartLink) return;
        if (!count || count <= 0) return;
        var badge = document.createElement('span');
        badge.className = 'cart-badge';
        badge.textContent = count;
        cartLink.classList.add('position-relative');
        cartLink.appendChild(badge);
      }

      // Attach to add-to-cart forms
      document.querySelectorAll('form.add-to-cart-form').forEach(function(form){
        form.addEventListener('submit', function(e){
          // If JS fetch is not available, allow normal submit
          if (!window.fetch) return;
          e.preventDefault();

          var btn = form.querySelector('button[type="submit"]');
          if (btn) {
            btn.disabled = true;
            var originalText = btn.innerHTML;
            btn.innerHTML = 'Adding…';
          }

          var data = new FormData(form);

          // Send request as fetch; include X-Requested-With header for server side detection
          fetch(form.action || 'cart.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: data
          }).then(function(resp){
            return resp.json ? resp.json() : resp.text();
          }).then(function(json){
            if (typeof json === 'object' && json !== null) {
              if (json.status === 'ok') {
                showFlash(json.message || 'Added to cart', 'success');
                updateCartBadge(json.cart_count || 0);
              } else {
                showFlash(json.message || 'Unable to add to cart', 'danger');
              }
            } else {
              // Unexpected non-JSON reply
              showFlash('Added to cart', 'success');
            }
          }).catch(function(err){
            // On error: fallback to normal submission (so non-JS usability remains), or show message
            console.error('Add to cart failed', err);
            showFlash('Add to cart failed. Please refresh the page.', 'danger');
          }).finally(function(){
            if (btn) {
              btn.disabled = false;
              btn.innerHTML = originalText;
            }
          });
        });
      });

      // Attach to remove-from-cart forms to allow AJAX removal as well
      document.querySelectorAll('form.remove-from-cart-form').forEach(function(form){
        form.addEventListener('submit', function(e){
          if (!window.fetch) return;
          e.preventDefault();

          if (!confirm('Remove item from cart?')) return;

          var btn = form.querySelector('button[type="submit"]');
          if (btn) { btn.disabled = true; var originalText = btn.innerHTML; btn.innerHTML = 'Removing…'; }

          var data = new FormData(form);

          fetch(form.action || 'cart.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: data
          }).then(function(resp){
            return resp.json ? resp.json() : resp.text();
          }).then(function(json){
            if (typeof json === 'object' && json !== null) {
              if (json.status === 'ok') {
                showFlash(json.message || 'Removed', 'success');
                updateCartBadge(json.cart_count || 0);
                // Remove the row from the table for immediate UX
                // Walk up to nearest <tr>
                var tr = form.closest('tr');
                if (tr) tr.remove();
                // If cart is now empty, reload to show empty state (simple)
                if (!document.querySelector('table.table tbody tr')) {
                  setTimeout(function(){ location.reload(); }, 600);
                }
              } else {
                showFlash(json.message || 'Unable to remove', 'danger');
              }
            } else {
              showFlash('Removed', 'success');
              setTimeout(function(){ location.reload(); }, 600);
            }
          }).catch(function(err){
            console.error('Remove failed', err);
            showFlash('Remove failed. Please refresh the page.', 'danger');
          }).finally(function(){
            if (btn) { btn.disabled = false; btn.innerHTML = originalText; }
          });
        });
      });

    })();
    </script>

</body>
</html>