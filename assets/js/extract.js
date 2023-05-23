// import Sortable from 'sortablejs/modular/sortable.complete.esm.js';
import { Sortable, Swap } from 'sortablejs/modular/sortable.core.esm';
Sortable.mount(new Swap());
document.addEventListener("DOMContentLoaded", () => {
    Sortable.create(document.getElementById('sortref'),{
        easing: "cubic-bezier(0.11, 0, 0.5, 0)",
        animation: 150,
        ghostClass: 'highlighted',
        onEnd(event){
            let arrayOrder = [];
            for (let el of document.querySelectorAll("#container-reference")){
                arrayOrder.push(el.dataset.idref)
            }
            let strOrder = arrayOrder.join(';');
            let hiddenRefNode = document.getElementById('document_orderRef')
            hiddenRefNode.value = strOrder;
        }
    });
    changeValueFormByToggled();
    changeValueOfReference();
    openModalAddBtn();
    closeInfoAlert();
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
            modifyReferenceText.classList.remove("hidden");
            modifyReferenceDoi.classList.remove("hidden");
            acceptModifyBtn.classList.remove("hidden");
            cancelModifyBtn.classList.remove("hidden");
            modifyReferenceText.classList.add("w-full");
            modifyReferenceDoi.classList.add("w-1/2");
            acceptModifyBtn.classList.add("inline-block");
            cancelModifyBtn.classList.add("inline-block");
            btnModify.classList.remove("inline-block");
            btnModify.classList.add("hidden");
            cancelModifyBtn.addEventListener('click', (event) => {
                modifyReferenceText.classList.remove("w-full");
                modifyReferenceDoi.classList.remove("w-1/2");
                acceptModifyBtn.classList.remove("inline-block");
                cancelModifyBtn.classList.remove("inline-block");
                modifyReferenceText.classList.add("hidden");
                modifyReferenceDoi.classList.add("hidden");
                acceptModifyBtn.classList.add("hidden");
                cancelModifyBtn.classList.add("hidden");
                btnModify.classList.remove("hidden");
                btnModify.classList.add("inline-block");
            });
            acceptModifyBtn.addEventListener('click', (ev) => {
                modifyReferenceText.classList.remove("w-full");
                modifyReferenceDoi.classList.remove("w-1/2");
                acceptModifyBtn.classList.remove("inline-block");
                cancelModifyBtn.classList.remove("inline-block");
                modifyReferenceText.classList.add("hidden");
                modifyReferenceDoi.classList.add("hidden");
                acceptModifyBtn.classList.add("hidden");
                cancelModifyBtn.classList.add("hidden");
                btnModify.classList.remove("hidden");
                btnModify.classList.add("inline-block");
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
function openModalAddBtn(){
    document.getElementById("btn-modal-addref").addEventListener('click',(event) => {
        let container = document.getElementById("modal-container");
        let boxcontainer = document.getElementById("box-container");
        let greybg = document.getElementById("greybg");
        greybg.classList.remove("-z-50","opacity-0");
        greybg.classList.add("z-49","opacity-1",'anim-box-popup');

        container.classList.remove("-z-50","opacity-0");
        container.classList.add("z-50","opacity-1",'anim-box-popup');

        boxcontainer.classList.add("z-50","opacity-1",'anim-box-popup');
        boxcontainer.classList.remove("opacity-0","-z-50");
    });
    document.getElementById("cancel-adding").addEventListener('click',(event) => {
        let container = document.getElementById("modal-container");
        let boxcontainer = document.getElementById("box-container");
        let greybg = document.getElementById("greybg");
        greybg.classList.add("-z-50","opacity-0");
        greybg.classList.remove("z-49","opacity-1");

        container.classList.add("-z-50","opacity-0");
        container.classList.remove("z-50","opacity-1");

        boxcontainer.classList.add("-z-50","opacity-0");
        boxcontainer.classList.remove("z-50","opacity-1");

    });
}

function closeInfoAlert() {
    document.querySelector("button#alert-drag-drop").addEventListener('click',(event)=>{
        event.preventDefault();
        document.querySelector("div#alert-drag-drop").classList.add('hidden');
    });
}