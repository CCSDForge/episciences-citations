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

});

function remove () {
    let removesBtns = document.querySelectorAll("button.remove-ref");
    let removeBtn;
    for (removeBtn of removesBtns) {
        removeBtn.addEventListener("click", (event) =>
        {
            let ref = {
                idRef: event.target.dataset.idref,
                docId: event.target.dataset.iddoc
            };
            postData("/removeref", ref).then((data) => {
                if (data.status === 200) {
                    event.target.parentElement.parentElement.remove();
                }
            }).catch((error) => {
                console.log(error);
            })
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
