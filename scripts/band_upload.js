// 会場新規作成
const venueSelect = document.getElementById("venue");
const newVenueInput = document.getElementById("new_venue");
function selectNewVenue(){
const venueValue = venueSelect.value;
    if(venueValue === "new"){
        newVenueInput.classList.add("visible");
    }else{
        newVenueInput.classList.remove("visible");
    };
}
document.addEventListener("DOMContentLoaded",selectNewVenue);
venueSelect.addEventListener("change",selectNewVenue);