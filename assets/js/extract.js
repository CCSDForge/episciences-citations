// import Sortable from 'sortablejs/modular/sortable.complete.esm.js';
import { Sortable, Swap } from 'sortablejs/modular/sortable.core.esm';
Sortable.mount(new Swap());
document.addEventListener("DOMContentLoaded", () => {
    remove();
    Sortable.create(document.getElementById('sortref'),{
        handle: '.handle', // handle's class
        swap: true, // Enable swap plugin
        swapClass: 'highlighted', // The class applied to the hovered swap item
        easing: "cubic-bezier(0.11, 0, 0.5, 0)",
        animation: 150,
        onUpdate(event){
            let arrayOrder = [];
            for (let el of document.querySelectorAll(".list-none")){
                arrayOrder.push(el.dataset.idref)
            }
            let strOrder = arrayOrder.join(';');
            let hiddenRefNode = document.getElementById('document_orderRef')
            hiddenRefNode.value = strOrder;
        }
    });
    changeValueFormByToggled();
});

function changeValueFormByToggled() {
    let toggles = document.querySelectorAll("#toggle-input");
    for (let toggle of toggles) {
        toggle.addEventListener("click", (event) =>
        {
            let radiosBtns = document.querySelector("#radio-group-choice-"+toggle.value).getElementsByTagName('input');
            for (let radioBtn of radiosBtns){
                if (Number(radioBtn.value) === Number(toggle.checked)){
                    radioBtn.setAttribute('checked','true');
                } else {
                    radioBtn.removeAttribute('checked');

                }

            }
            // //document.querySelectorAll("[data-foo='1']")
        })
    }
}


async function postData(url = "", data = {}) {
    const response = await fetch(url, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
        },
        body: JSON.stringify(data),
    });
    return response.json();
}
