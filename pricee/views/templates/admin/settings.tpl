{*
* Shop scope: while the shop uses the shared credentials, its own credential
* fields are not used, so they are disabled.
*}
<script>
(function() {
    var fields = ['PRICEE_CLIENT_ID', 'PRICEE_API_KEY'].map(function(id) {
        return document.getElementById(id);
    });
    var shared = document.getElementById('PRICEE_SHARED_CREDENTIALS_on');
    var own = document.getElementById('PRICEE_SHARED_CREDENTIALS_off');
    if (!shared || !own) {
        return;
    }

    var update = function() {
        fields.forEach(function(field) {
            if (field) {
                field.disabled = shared.checked;
            }
        });
    };

    shared.addEventListener('change', update);
    own.addEventListener('change', update);
    update();
})();
</script>
