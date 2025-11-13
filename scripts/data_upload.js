//プレビューテーブルがDBの名前と一致
// 一致するか検証してクラス変える関数の定義
const validateUsername = async(inputElement) => {
    const input_name = inputElement.value;

    // なんもなかったらvalueを消す
    if (input_name === "") {
        inputElement.classList.remove("valid","invalid");
        return;
    }
    // check.phpに入力内容送信
    try{
        const responce = await fetch("preview_name_check.php",{
            method: "POST",
            headers:{
                "Content-Type":'application/x-www-form-urlencoded',
            },
            body: "input_name="+ encodeURIComponent(input_name)
        });
        
        // サーバーからの結果受取
        const result = await responce.json();
        
        // 結果に応じてクラスとname変更
        // もとのname属性を保存
        const master_name = inputElement.getAttribute("data-master-name");

        // DBにいる
        if (result.exists) {
            inputElement.classList.add("valid");
            inputElement.classList.remove("invalid");
            inputElement.setAttribute("name",master_name);

        // DBにいない
        }else{
            inputElement.classList.add("invalid");
            inputElement.classList.remove("valid");

            // const new_input = document.getElementById("new");
            // new_input.value = 
            const new_name = inputElement.getAttribute("name");
            if(!new_array.includes(new_name)){
                new_array.push(new_name);
            }

            // inputのname属性を変更
            // const new_name = master_name + "_new";
            // inputElement.setAttribute("name",new_name);
        }
    }catch(error){
        console.error("通信エラー:",error);
    }
};

// ロード完了後
let new_array = [];
document.addEventListener("DOMContentLoaded",() =>{

    // text_previewクラスを持つ全要素取得
    const text_preview_element = document.querySelectorAll(".text_preview");
    text_preview_element.forEach((input) => {
        validateUsername(input);               
        // 入力待ちタイマー
        let typingTimer;

        // 入力を検知
        input.addEventListener("input",() => {
            clearTimeout(typingTimer);

            // タイマー切れたら検証
            typingTimer = setTimeout(() =>{
                validateUsername(input);
            },30);
        });
    });
});
    
const togglebuttons = document.querySelectorAll(".toggle_button");
togglebuttons.forEach(button => {
    button.addEventListener("click",() => {
        const content = button.nextElementSibling;
        content.classList.toggle("active");
    })
});

// 会場新規作成
const venueSelect = document.getElementById("venue");
const newVenueInput = document.getElementById("new_venue");
venueSelect.addEventListener("change",()=>{
    const venueValue = venueSelect.value;
    if(venueValue === "new"){
    newVenueInput.classList.add("visible");
    }else{
        newVenueInput.classList.remove("visible");
    };
});
