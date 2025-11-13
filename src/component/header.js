"usestrict";

const userClick = document.getElementById("user");
const userMenu = document.querySelector(".user__menu");

userClick.addEventListener("click",()=>{
    userMenu.classList.toggle("visible");
});
document.addEventListener("click",(e)=>{
    const  clickInsidebutton = userClick.contains(e.target);
    const cilickInsidemenu = userMenu.contains(e.target);

    if(
        userMenu.classList.contains("visible") &&
        !clickInsidebutton &&
        !cilickInsidemenu
    ){
        userMenu.classList.remove("visible")
    }
});