document.addEventListener('DOMContentLoaded', function() {
    const chatMessages = document.getElementById('chat-messages');
    
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    
    const starRatings = document.querySelectorAll('.star-rating:not(.readonly)');
    
    starRatings.forEach(ratingContainer => {
        const stars = ratingContainer.querySelectorAll('input[type="radio"]');
        const labels = ratingContainer.querySelectorAll('label');
        
        labels.forEach((label, index) => {
            label.addEventListener('mouseover', function() {
                resetStars(stars, labels);
                highlightStars(stars, labels, index);
            });
            
            label.addEventListener('click', function() {
                resetStars(stars, labels);
                highlightStars(stars, labels, index);
            });
        });
        
        ratingContainer.addEventListener('mouseleave', function() {
            const checkedStar = ratingContainer.querySelector('input:checked');
            if (checkedStar) {
                resetStars(stars, labels);
                const checkedIndex = Array.from(stars).indexOf(checkedStar);
                highlightStars(stars, labels, checkedIndex);
            }
        });
    });
    
    function resetStars(stars, labels) {
        labels.forEach(label => {
            label.style.color = '#e2e8f0';
        });
    }
    
    function highlightStars(stars, labels, index) {
        for (let i = 0; i <= index; i++) {
            labels[i].style.color = '#ffc107';
        }
    }
    
    const chatForm = document.querySelector('.chat-input-form');
    
    if (chatForm) {
        const chatInput = chatForm.querySelector('textarea');
        
        chatInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                chatForm.submit();
            }
        });
    }
    
    const conversationItems = document.querySelectorAll('.conversation-item');
    
    conversationItems.forEach(item => {
        item.addEventListener('click', function(e) {
            if (!this.getAttribute('href')) {
                e.preventDefault();
            }
        });
    });
});