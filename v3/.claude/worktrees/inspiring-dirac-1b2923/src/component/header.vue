<template>
    <header class="site_header">
        <div class="logo">
            <a href="/"><img src="/src/assets/online.png" alt="AbbeyRoad.online"></a>
        </div>
        <nav class="main-nav">
            <ul>
                <li><a href="/"></a>ホーム</li>
                <li><a href="database_live.php">ライブデータベース《準備中》</a></li>
                <li><a href="/user_compile.php">ユーザー情報編集</a></li>
            </ul>
        </nav>
    </header>
</template>

<script setup>
</script>

<style lang="css" scoped>

.site_header{
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 20px;
    background-color: #ffffff;
    color: white;
}

.logo :hover{
    filter:drop-shadow(1px 1px 5px gray);
}

.main-nav{
    list-style: none;
    font-size: 1.5em;
    font-weight: bold;
    text-decoration: none;
}
.main-nav li{
    margin-left: 20px;
}
.main-nav a{
    color: black;
}
.main-nav a:hover{
    color: rgb(235, 57, 57);
    transition: 0.3s;
}

</style>