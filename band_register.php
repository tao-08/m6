<?php
session_start();
//DB設定
require("DB_connect.php");
$pdo = DBconnect();

$n = $_POST["number"] + 1;




// for ($i=0; $i < $n; $i++) {
    
// }




// for ($i=0; $i < $n; $i++) {
//     $post_band = $i."_preview_band_new";
//     $post_vocal = $i."_preview_vocal_new";
//     $post_guiter_1 = $i."_preview_guiter_1_new";
//     $post_guiter_2 = $i."_preview_guiter_2_new";
//     $post_bass = $i."_preview_bass_new";
//     $post_drum = $i."_preview_drum_new";
//     $post_keybord = $i."_preview_keybord_new";
//     $post_songs = $i."_preview_songs_new";

    $register_band = $_POST[$post_band] ?? null;
    $register_vocal = $_POST[$post_vocal] ?? null;
    $register_guiter_1 = $_POST[$post_guiter_1] ?? null;
    $register_guiter_2 = $_POST[$post_guiter_2] ?? null;
    $register_bass = $_POST[$post_bass] ?? null;
    $register_drum = $_POST[$post_drum] ?? null;
    $register_keybord = $_POST[$post_keybord] ?? null;
    $register_songs = $_POST[$post_songs] ?? null;

    // $member_register[]=[
    //     $register_band = $_POST[$post_band] ?? null,
    //     $register_vocal = $_POST[$post_vocal] ?? null,
    //     $register_guiter_1 = $_POST[$post_guiter_1] ?? null,
    //     $register_guiter_2 = $_POST[$post_guiter_2] ?? null,
    //     $register_bass = $_POST[$post_bass] ?? null,
    //     $register_drum = $_POST[$post_drum] ?? null,
    //     $register_keybord = $_POST[$post_keybord] ?? null,
    //     $register_songs = $_POST[$post_songs] ?? null
    // ];

    // $sql = "INSERT IGNORE into member (name) VALUE (:name)";
    // $stmt= $pdo->prepare($sql);
    
    // foreach($member_register[$i] as $a){
    //     $stmt->bindParam(":name",$a);
    // }
        // "INSERT into band_master (id_live_detail,name,order_live,vocal_1,guiter_1,guiter_2,bass_1,drum_1,keybord_1,songs)
        // VALUES(
        //     :id_live_detail,
        //     :name,
        //     :order_live,
        //     :vocal_1,
        //     :guiter_1,
        //     :guiter_2,
        //     :bass_1,
        //     :drum_1,
        //     :keybord_1,
        //     :songs
        // );";

    // $stmt = $pdo->prepare($sql);
    
    // $p = $i + 1;

    // $stmt->bindParam(':id_live_detail',$p,pdo::PARAM_INT);
    // $stmt->bindParam(':name',$register_band,pdo::PARAM_STR);
    // $stmt->bindParam(':order_live',$p,pdo::PARAM_INT);
    // $stmt->bindParam(':vocal_1',$register_vocal,pdo::PARAM_STR);
    // $stmt->bindParam(':guiter_1',$register_guiter_1,pdo::PARAM_STR);
    // $stmt->bindParam(':guiter_2',$register_guiter_2,pdo::PARAM_STR);
    // $stmt->bindParam(':bass_1',$register_bass,pdo::PARAM_STR);
    // $stmt->bindParam(':drum_1',$register_drum,pdo::PARAM_STR);
    // $stmt->bindParam(':keybord_1',$register_keybord,pdo::PARAM_STR);
    // $stmt->bindParam(':songs',$register_songs,pdo::PARAM_int);

//     $result = $stmt->execute();
// }

?>