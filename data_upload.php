<?php
    session_start();
    $band_label = "バンド名";
    $vocal_label = "ボーカル";
    $guiter_1_label = "ギター1";
    $guiter_2_label = "ギター2";

?>
<!DOCTYPE HTML>
<head>
    <title>新規データ登録</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .toggle_button {
            width: 100%;
            text-align: left;
            padding: 10px;
            background-color: #007bff;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }
        .toggle_content{
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease-out;
            border: 1px solid #ccc;
            padding: 0 10px;
        }
        .toggle_content.active{
            max-height: 200px;
            padding: 10px;
        }
    </style>
</head>
<body>
    <h1>新規データ登録</h1>
    <div class="box">
        <form action="" method="post">
            <input type="date" name="date">
            <button class="toggle_button">読み込みファイルの設定</button>
            <div class=>
                <label for="band_label">バンド名の列の名前</label>
                <input type="text" name="band_label" id="band_label" value="<?php echo $band_label?>">
            </div>
        </form>
    </div>
    <script>
        const togglebuttons = document.querySelectorAll(".toggle_button");
        togglebuttons.forEach(button => {
            button.addEventListener("click",() => {
                const content = button.nextElementSibling;
                content.classList.toggle("active");
            })
        });
    </script>
</body>
</html>