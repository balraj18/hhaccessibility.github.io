
var time_limit = 5; // seconds
var timer;

// hides hover text message immediately 
// and erases any timer ids.
function hideHoverText()
{
	showHoverText("");
	timer = undefined; // clear timeout.
}

// shows text that gets hidden after a few seconds
function delayedHide(messageToShow)
{
	showHoverText(messageToShow);
	if ( timer )
	{
		// cancel older timeout that would 
		// otherwise hide the hover text.
		clearTimeout(timer);
	}
	timer = setTimeout(hideHoverText,time_limit*1000);
}

$(document).ready(function(){
   $(".location-tag a").hover(function(){
      	delayedHide($(this).attr('title'));
   }); 
});

// shows specified text immediately
function showHoverText(messageToShow) {
	var newDivForMessage = $('#hover-text-display');

	// if message not added yet, create the new div for it.
	if ( newDivForMessage.length === 0 )
	{
		newDivForMessage = $('<div />');
		newDivForMessage.attr('id', 'hover-text-display');
		 $('#main').append(newDivForMessage);
	}

	// replace the message in the element.
	newDivForMessage.text(messageToShow);
};

