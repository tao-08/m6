"usestrict";

const userClick = document.getElementById("user");
const userMenu = document.getElementsByClassName("user__menu");

userClick.addEventListener("click",()=>{
    userMenu.classlist.toggle("visible");
});