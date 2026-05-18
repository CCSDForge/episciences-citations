import './styles/app.scss';
import { Toast } from 'bootstrap';
import 'bootstrap';
import './bootstrap';

require('@fortawesome/fontawesome-free/css/all.min.css');
require('@fortawesome/fontawesome-free/js/all.js');

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.flash-toast').forEach(el => {
        new Toast(el, { autohide: true, delay: 5000 }).show();
    });
});
