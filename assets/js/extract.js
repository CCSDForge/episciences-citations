document.addEventListener("DOMContentLoaded", () => {
    remove();
});

function remove () {
    let removesBtns = document.querySelectorAll("button.remove-ref");
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
