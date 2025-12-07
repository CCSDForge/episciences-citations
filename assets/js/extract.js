import { Sortable } from 'sortablejs/modular/sortable.core.esm';
document.addEventListener("DOMContentLoaded", () => {
    if (document.getElementById('sortref')){
        let sortEl = Sortable.create(document.getElementById('sortref'),{
            easing: "cubic-bezier(0.11, 0, 0.5, 0)",
            animation: 150,
            ghostClass: 'highlighted',
            filter: '.filtered',
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
        disabledSortWhenChangeRef(sortEl);
    }
    changeValueFormByToggled();
    changeValueOfReference();
    openModalAddBtn();
    closeInfoAlert();
    closeFlashMessage();
    acceptAllReference();
    declineAllReference();
    showLoadingScreen();
    hidePopUpAdding();
    topAnchor();
    openClosingModalWindow();
    rextract();
    checkIsDirty();
    manageBibtex()
    removeReference();
});

function changeValueFormByToggled() {
    let toggles = document.querySelectorAll("[id^=toggle-input-]");
    for (let toggle of toggles) {
        toggle.addEventListener("click", (event) =>
        {
            let radiosBtns = document.querySelector("#radio-group-choice-"+toggle.value).getElementsByTagName('input');
            for (let radioBtn of radiosBtns){
                radioBtn.checked = Number(radioBtn.value) === Number(toggle.checked);
            }
            let idRef = toggle.value;
            let containerBox = document.querySelector(`div[data-idref="${idRef}"]`);
            if (toggle.checked){
                classWhenConfirmDecline(containerBox,true);
            } else {
                classWhenConfirmDecline(containerBox,false);
            }

        });
    }
}
function disabledSortWhenChangeRef(sortEl) {
    let btnModifys = document.querySelectorAll("#modifyBtn");
    for (let btnModify of btnModifys) {
        btnModify.addEventListener("click", (event) =>
        {
            sortEl.option("disabled",true); // set
            let acceptModifyBtn = document.querySelector("#acceptModifyBtn-"+event.target.dataset.idref);
            let cancelModifyBtn = document.querySelector("#cancelModifyBtn-"+event.target.dataset.idref);
            cancelModifyBtn.addEventListener('click', (event) => {
                sortEl.option("disabled",false);
            });
            acceptModifyBtn.addEventListener('click', (ev) => {
                sortEl.option("disabled",false);
            });
        });
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
            let containerInfo = document.querySelector("#container-reference-informations-"+event.target.dataset.idref);
            document.querySelector('#textareaRef-'+event.target.dataset.idref).addEventListener('input',(e)=> {
                document.querySelector(`input[data-dirty-ref="${event.target.dataset.idref}"]`).value = 1;
            });
            modifyReferenceText.classList.remove("hidden");
            modifyReferenceDoi.classList.remove("hidden");
            acceptModifyBtn.classList.remove("hidden");
            cancelModifyBtn.classList.remove("hidden");
            modifyReferenceText.classList.add("w-full");
            modifyReferenceDoi.classList.add("w-1/2");
            btnModify.classList.remove("inline-block");
            btnModify.classList.add("hidden");
            containerInfo.classList.add("hidden");
            cancelModifyBtn.addEventListener('click', (e) => {
                modifyReferenceText.classList.remove("w-full");
                modifyReferenceDoi.classList.remove("w-1/2");
                modifyReferenceText.classList.add("hidden");
                modifyReferenceDoi.classList.add("hidden");
                acceptModifyBtn.classList.add("hidden");
                cancelModifyBtn.classList.add("hidden");
                btnModify.classList.remove("hidden");
                btnModify.classList.add("inline-block");
                containerInfo.classList.remove("hidden");
                document.querySelector(`input[data-dirty-ref="${event.target.dataset.idref}"]`).value = 0;
            });
            acceptModifyBtn.addEventListener('click', (ev) => {
                containerInfo.classList.remove("hidden");
                modifyReferenceText.classList.remove("w-full");
                modifyReferenceDoi.classList.remove("w-1/2");
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
                let doiContent = '';
                if (linkDoiTag === null && referenceDoiWished.value !== "") {
                    let newNode = document.createElement('a');
                    newNode.id = 'linkDoiRef-'+event.target.dataset.idref;
                    newNode.className = 'underline text-blue-600 hover:text-blue-800 visited:text-purple-600';
                    showedText.after(newNode);
                    linkDoiTag = document.getElementById('linkDoiRef-'+event.target.dataset.idref);
                    doiContent = linkDoiTag.textContent
                }
                if (referenceDoiWished.value !== ""){
                    // Sanitize DOI value to prevent XSS
                    const sanitizedDoi = referenceDoiWished.value.trim();
                    // Prevent javascript: protocol or other malicious schemes
                    if (sanitizedDoi.toLowerCase().startsWith('javascript:') ||
                        sanitizedDoi.toLowerCase().startsWith('data:') ||
                        sanitizedDoi.toLowerCase().startsWith('vbscript:')) {
                        console.error('Invalid DOI value detected');
                        return;
                    }
                    // Build safe URL
                    linkDoiTag.href = "https://doi.org/" + encodeURIComponent(sanitizedDoi);
                    linkDoiTag.textContent = sanitizedDoi;
                    doiContent = sanitizedDoi;
                } else if (referenceDoiWished.value === "" && linkDoiTag !== null) {
                    linkDoiTag.remove();
                }
                acceptRefModificationsDone(event.target.dataset.idref);
                let modifiedInformations = JSON.stringify({'raw_reference':showedText.value,'doi':doiContent});
                let referenceValueForm = document.getElementById('reference-'+event.target.dataset.idref);
                referenceValueForm.value = '['+JSON.stringify(modifiedInformations)+']';
            });
        });
    }
}

function acceptRefModificationsDone(idRef){
    let toggle = document.querySelector("#toggle-input-"+idRef);
    if (!toggle.checked){
        toggle.click();
    }
    let containerBox = document.querySelector(`div[data-idref="${idRef}"]`);
    classWhenConfirmDecline(containerBox,true);
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
    if (document.querySelector("#alert-remove")){
        document.querySelector("button#alert-remove").addEventListener('click',(event)=>{
            event.preventDefault();
            document.querySelector("div#alert-remove").classList.add('hidden');
        });
    }
}

function closeFlashMessage(){
    document.querySelectorAll('#flash-message').forEach(function(e) {
        setTimeout(function () {
            e.remove();
        },5000);
    });
}

function acceptAllReference(){
    document.querySelector("#accept-all").addEventListener('click',(event) => {
        event.preventDefault();
        let toggles = document.querySelectorAll("[id^=toggle-input-]");
        for (let toggle of toggles) {
            toggle.addEventListener("click", (event) =>
            {
                let radiosBtns = document.querySelector("#radio-group-choice-"+toggle.value).getElementsByTagName('input');
                for (let radioBtn of radiosBtns){
                    radioBtn.checked = Number(radioBtn.value) === Number(toggle.checked);
                }
            });
            if (!toggle.checked){
                toggle.click();
            }
        }
        (document.querySelectorAll(".declinedRef")).forEach((el) => {
            classWhenConfirmDecline(el,true);
        });
    });
}

function declineAllReference(){
    document.querySelector("#decline-all").addEventListener('click',(event) => {
        event.preventDefault();
        let toggles = document.querySelectorAll("[id^=toggle-input-]");
        for (let toggle of toggles) {
            toggle.addEventListener("click", (event) =>
            {
                let radiosBtns = document.querySelector("#radio-group-choice-"+toggle.value).getElementsByTagName('input');
                for (let radioBtn of radiosBtns){
                    radioBtn.checked = Number(radioBtn.value) === Number(toggle.checked);
                }
            });
            if (toggle.checked){
                toggle.click();
            }
        }
        (document.querySelectorAll("#container-reference")).forEach((el) => {
            classWhenConfirmDecline(el,false);
        });
    });
}
function showLoadingScreen(){
    document.getElementById("form-extraction").addEventListener("submit", (event) => {
        document.getElementById('loading-screen').classList.remove('hidden');
    });
}

function hidePopUpAdding(){
    document.getElementById('confirm-adding').addEventListener('click',(event) => {
        let container = document.getElementById("modal-container");
        let boxcontainer = document.getElementById("box-container");
        let greybg = document.getElementById("greybg");

        greybg.classList.add("-z-50","opacity-0");
        greybg.classList.remove("z-49","opacity-1");

        container.classList.add("-z-50","opacity-0");
        container.classList.remove("z-50","opacity-1");

        boxcontainer.classList.add("-z-50","opacity-0");
        boxcontainer.classList.remove("z-50","opacity-1");
    })
}

function classWhenConfirmDecline(el, confirm = true){
    if (typeof confirm === 'undefined') { confirm = true; }
    if (confirm){
        el.classList.remove("declinedRef");
        el.classList.remove("filtered");
    } else {
        el.classList.add("declinedRef");
        el.classList.add("filtered");
    }
}

function topAnchor(){
    document.getElementById('document_save').addEventListener('click',(event) => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
    document.getElementById('confirm-adding').addEventListener('click',(event) => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

function openClosingModalWindow(){
    let modalWindow = document.querySelector('#closing-window-modal-container');
    if (modalWindow) {
        let boxcontainer = document.getElementById("box-container-closing");
        let greybg = document.getElementById("greybg-closing");
        greybg.classList.remove("-z-50","opacity-0");
        greybg.classList.add("z-49","opacity-1",'anim-box-popup');

        modalWindow.classList.remove("-z-50","opacity-0");
        modalWindow.classList.add("z-50","opacity-1",'anim-box-popup');

        boxcontainer.classList.add("z-50","opacity-1",'anim-box-popup');
        boxcontainer.classList.remove("opacity-0","-z-50");

        document.getElementById('cancel-closing-app').addEventListener('click',(event) => {
            greybg.classList.add("-z-50","opacity-0");
            greybg.classList.remove("z-49","opacity-1");

            modalWindow.classList.add("-z-50","opacity-0");
            modalWindow.classList.remove("z-50","opacity-1");

            boxcontainer.classList.add("-z-50","opacity-0");
            boxcontainer.classList.remove("z-50","opacity-1");
        });
    }
}

function rextract(){
    document.getElementById("extract-all").onclick = function (event) {
        event.preventDefault();
        location.href = "/extract?url="+this.dataset.urlFromEpi+"&rextract";
        document.getElementById('loading-screen').classList.remove('hidden');
    };
}
function checkIsDirty(){
    let isDirty = document.getElementById('is-dirty');
    let formSubmitting = false;
    document.getElementById("form-extraction").addEventListener('change',(event) => {
        isDirty.value = "1";
    });
    document.getElementById("form-extraction").addEventListener("submit", (event) => {
        formSubmitting = true;
    });
    window.addEventListener("beforeunload", function(event) {
        if (isDirty.value === "1" && formSubmitting === false) {
            event.returnValue = null;
        }
    });
}

function manageBibtex(){
    let importbibtex= document.querySelector("#btn-modal-importbibtex");
    let container = document.getElementById("modal-container-bib");
    let boxcontainer = document.getElementById("box-container-bib");
    let greybg = document.getElementById("greybg-bib");
    importbibtex.addEventListener('click', (event) => {
        greybg.classList.remove("-z-50","opacity-0");
        greybg.classList.add("z-49","opacity-1",'anim-box-popup');

        container.classList.remove("-z-50","opacity-0");
        container.classList.add("z-50","opacity-1",'anim-box-popup');

        boxcontainer.classList.add("z-50","opacity-1",'anim-box-popup');
        boxcontainer.classList.remove("opacity-0","-z-50");
    });
    document.getElementById("cancel-adding-bib").addEventListener('click',(event) => {
        greybg.classList.add("-z-50","opacity-0");
        greybg.classList.remove("z-49","opacity-1");

        container.classList.add("-z-50","opacity-0");
        container.classList.remove("z-50","opacity-1");

        boxcontainer.classList.add("-z-50","opacity-0");
        boxcontainer.classList.remove("z-50","opacity-1");

    });
    document.getElementById("confirm-adding-bib").addEventListener('click',(event) => {
        greybg.classList.add("-z-50","opacity-0");
        greybg.classList.remove("z-49","opacity-1");

        container.classList.add("-z-50","opacity-0");
        container.classList.remove("z-50","opacity-1");

        boxcontainer.classList.add("-z-50","opacity-0");
        boxcontainer.classList.remove("z-50","opacity-1");

    });
}


function removeReference(){
    let deleteBtn = document.getElementById("select-delete-ref");
    let cancelBtn = document.getElementById("cancel-delete-ref");
    if (deleteBtn && cancelBtn){
        deleteBtn.addEventListener('click',(event) => {
            event.preventDefault();
            document.getElementById("alert-remove").classList.remove('hidden');
            document.querySelectorAll('#selection-references').forEach(node => {
                node.classList.add('hidden');
            });
            document.querySelectorAll('#ref-to-delete').forEach(node => {
                node.classList.remove('hidden');
            });
            deleteBtn.classList.add('hidden');
            cancelBtn.classList.remove('hidden');

        });
        cancelBtn.addEventListener('click',(event) => {
            event.preventDefault();
            document.getElementById("alert-remove").classList.add('hidden');
            document.querySelectorAll('#ref-to-delete').forEach(node => {
                node.value = '0';
            });
            deleteBtn.classList.remove('hidden');
            cancelBtn.classList.add('hidden');
            document.querySelectorAll('#selection-references').forEach(node => {
                node.classList.remove('hidden');
            });
            document.querySelectorAll('#ref-to-delete').forEach(node => {
                node.classList.add('hidden');
                node.checked = false;
            });
        });
    }
}
