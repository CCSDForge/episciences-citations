// import Sortable from 'sortablejs/modular/sortable.complete.esm.js';
import { Sortable, Swap } from 'sortablejs/modular/sortable.core.esm';
Sortable.mount(new Swap());
document.addEventListener("DOMContentLoaded", () => {
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
    changeValueOfReference();
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

        })
    }
}

function changeValueOfReference() {
    let btnModifys = document.querySelectorAll("#modifyBtn");
    for (let btnModify of btnModifys) {
        btnModify.addEventListener("click", (event) =>
        {
            let modifyReferenceText = document.querySelector("#modifyTextArea-"+event.target.dataset.idref);
            let modifyReferenceDoi = document.querySelector("#modifyReferenceDoi-"+event.target.dataset.idref);
            let acceptModifyBtn = document.querySelector("#acceptModifyBtn-"+event.target.dataset.idref);
            let cancelModifyBtn = document.querySelector("#cancelModifyBtn-"+event.target.dataset.idref);
            modifyReferenceText.style.display = 'block';
            modifyReferenceDoi.style.display = 'block';
            acceptModifyBtn.style.display = 'block';
            cancelModifyBtn.style.display = 'block';
            btnModify.style.display = 'none';
            cancelModifyBtn.addEventListener('click', (event) => {
                modifyReferenceText.style.display = 'none';
                modifyReferenceDoi.style.display = 'none';
                acceptModifyBtn.style.display = 'none';
                cancelModifyBtn.style.display = 'none';
                btnModify.style.display = 'block';
            });
            acceptModifyBtn.addEventListener('click', (ev) => {
                modifyReferenceText.style.display = 'none';
                modifyReferenceDoi.style.display = 'none';
                acceptModifyBtn.style.display = 'none';
                cancelModifyBtn.style.display = 'none';
                btnModify.style.display = 'block';
                let referenceWished = document.getElementById('textareaRef-'+event.target.dataset.idref);
                let showedText = document.getElementById("textReference-"+event.target.dataset.idref);
                showedText.textContent = referenceWished.value;
                showedText.value = referenceWished.value;
                let referenceDoiWished = document.getElementById('textDoiRef-'+event.target.dataset.idref);
                let linkDoiTag = document.getElementById('linkDoiRef-'+event.target.dataset.idref);
                if (linkDoiTag === null && referenceDoiWished.value !== "") {
                    let newNode = document.createElement('a');
                    newNode.id = 'linkDoiRef-'+event.target.dataset.idref;
                    newNode.className = 'underline text-blue-600 hover:text-blue-800 visited:text-purple-600';
                    showedText.after(newNode);
                    linkDoiTag = document.getElementById('linkDoiRef-'+event.target.dataset.idref);
                }
                if (referenceDoiWished.value !== ""){
                    linkDoiTag.href = "https://doi.org/"+referenceDoiWished.value;
                    linkDoiTag.text = referenceDoiWished.value;
                    linkDoiTag.textContent = referenceDoiWished.value;
                } else {
                    linkDoiTag.remove();
                }
                let modifiedInformations = JSON.stringify({'raw_reference':showedText.value,'doi':linkDoiTag.textContent});
                let referenceValueForm = document.getElementById('reference-'+event.target.dataset.idref);
                referenceValueForm.value = '['+JSON.stringify(modifiedInformations)+']';
            });
        });
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
