import './styles/app.scss';
import 'bootstrap';
import './bootstrap';

import '@fortawesome/fontawesome-free/css/fontawesome.min.css';
import '@fortawesome/fontawesome-free/css/solid.min.css';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.flash-toast').forEach((el) => {
        new Toast(el, { autohide: true, delay: 5000 }).show();
    });
});
