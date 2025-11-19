// const elementID = document.getElementById("date");
// elementID.addEventListener()

// バンド名一致しているか
const validateBandname = async(inputElement) => {
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
            body: "band_name="+ encodeURIComponent(input_name)
        });
        
        // サーバーからの結果受取
        const result = await responce.json();
        
        // 結果に応じてクラス変更
        // DBにいる
        if (result.exists === true) {
            inputElement.classList.add("valid");
            inputElement.classList.remove("invalid","partial");

            // DBにいない
        }else if (result.exists === false) {
            inputElement.classList.add("invalid");
            inputElement.classList.remove("valid","partial");
			
			// 部分一致
        }else if (result.exists == "partial"){
            inputElement.classList.add("partial");
            inputElement.classList.remove("valid","invalid");
			
		}
    }catch(error){
        console.error("通信エラー:",error);
    }
};




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
        // DBにいる
        if (result.exists) {
            inputElement.classList.add("valid");
            inputElement.classList.remove("invalid");
            const input_content = inputElement.getAttribute("name");
            const new_name = input_content.replace(/-new\]$/,"]")
            inputElement.setAttribute("name",new_name);
            
            // DBにいない
        }else{
            const input_content = inputElement.getAttribute("name");
            inputElement.classList.add("invalid");
            inputElement.classList.remove("valid");
            // inputのname属性を変更
            const new_name = input_content.replace(/\]$/,"-new]")
            inputElement.setAttribute("name",new_name);
        }
    }catch(error){
        console.error("通信エラー:",error);
    }
};

// ロード完了後
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
	
    // band_previewクラスを持つ全要素取得
    const band_preview_element = document.querySelectorAll(".band_preview");
    band_preview_element.forEach((input) => {
        validateBandname(input);               
        // 入力待ちタイマー
        let typingTimer;

        // 入力を検知
        input.addEventListener("input",() => {
            clearTimeout(typingTimer);

            // タイマー切れたら検証
            typingTimer = setTimeout(() =>{
                validateBandname(input);
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