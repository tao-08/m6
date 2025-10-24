<?php
    session_start();
    $band_label = "バンド名";
    $vocal_label = "Vo(Gt.)";
    $guiter_1_label = "Gt.1";
    $guiter_2_label = "Gt.2";
    $bass_label = "Ba.";
    $drum_label = "Dr.";
    $keybord_label = "Key.";
    $songs_label = "曲数";

?>
<!DOCTYPE HTML>
<head>
    <title>新規データ登録</title>
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
        .input_label{
            width: 4em;
            max-width: 100%;
        }
        

    </style>
    <link rel="stylesheet" href="style.css">

</head>
<body>
    <h1>新規データ登録</h1>
    <div class="box">
        <form action="" method="post">
            <div>
                <input type="date" name="date">
            </div>
            <h2>列名の設定</h2>
            <div class=>
                <table class="not-border">
                    <tr>
                        <th>バンド名</th>
                        <th>Vo(Gt.)</th>
                        <th>Gt.1</th>
                        <th>Gt.2</th>
                        <th>Ba.</th>
                        <th>Dr.</th>
                        <th>Key.</th>
                        <th>曲数</th>
                    </tr>
                    <tr>
                        <td>
                            <input type="text" name="band_label" class="input_label" value="<?php echo $band_label?>">
                        </td>
                        <td>
                            <input type="text" name="vocal_label" class="input_label" value="<?php echo $vocal_label?>">
                        </td>
                        <td>
                            <input type="text" name="guiter_1_label" class="input_label" value="<?php echo $guiter_1_label?>">
                        </td>
                        <td>
                            <input type="text" name="guiter_2_label" class="input_label" value="<?php echo $guiter_2_label?>">
                        </td>
                        <td>
                            <input type="text" name="bass_label" class="input_label" value="<?php echo $bass_label?>">
                        </td>
                        <td>
                            <input type="text" name="drum_label" class="input_label" value="<?php echo $drum_label?>">
                        </td>
                        <td>
                            <input type="text" name="keybord_label" class="input_label" value="<?php echo $keybord_label?>">
                        </td>
                        <td>
                            <input type="text" name="songs_label" class="input_label" value="<?php echo $songs_label?>">
                        </td>
                    </tr>
                
                </div>
            </table>
        </form>
        <button class="toggle_button">読み込みファイルの設定</button>

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