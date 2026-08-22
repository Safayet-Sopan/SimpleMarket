document.addEventListener('DOMContentLoaded', function () {
    var roleSelect = document.getElementById('role-select');
    var sellerFields = document.getElementById('seller-fields');
    var riderFields = document.getElementById('rider-fields');

    function toggleRoleFields() {
        if (!roleSelect) return;
        var role = roleSelect.value;
        if (sellerFields) sellerFields.style.display = (role === 'seller') ? 'block' : 'none';
        if (riderFields) riderFields.style.display = (role === 'rider') ? 'block' : 'none';
    }

    if (roleSelect) {
        roleSelect.addEventListener('change', toggleRoleFields);
        toggleRoleFields(); // run once on load too, in case role is still selected after a failed submit
    }
});