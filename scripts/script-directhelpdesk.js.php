<?php
use Glpi\Event;

include('../../../inc/includes.php');
header('Content-Type: text/javascript');
$add_text = __('Add');

?>

var newDiv = document.createElement('div');
newDiv.classList.add('center');
var newButton = document.createElement('button');
var add_text = "<?php echo $add_text ?>";

newButton.id ='launch-directhelpdesk-modal';
newButton.textContent = add_text;
newButton.classList.add('btn');
newButton.classList.add('btn-sm');
newButton.classList.add('btn-primary');
newButton.classList.add('me-1');
newDiv.appendChild(newButton);
// Get the existing button element with the specific class
var existingButton = document.querySelector('.trigger-fuzzy');

// Insert the new button before the existing button
existingButton.parentNode.insertBefore(newDiv, existingButton);

var modal = document.getElementById('directhelpdesk-modal');

var btn = document.getElementById('launch-directhelpdesk-modal');

btn.onclick = function() {
    $("#directhelpdesk-modal").modal('show');
}

// Fermer la modal lorsque l'utilisateur clique en dehors de la modal
window.onclick = function(event) {
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}
