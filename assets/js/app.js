(function(){
  'use strict';
  var root=document.documentElement;
  var menu=document.querySelector('.menu-toggle');
  var nav=document.getElementById('site-nav');
  if(menu&&nav){menu.addEventListener('click',function(){var open=nav.classList.toggle('open');menu.setAttribute('aria-expanded',String(open));menu.textContent=open?'✕':'☰';});}
  var theme=document.querySelector('.theme-toggle');
  if(theme){theme.addEventListener('click',function(){var next=root.dataset.theme==='dark'?'light':'dark';root.dataset.theme=next;try{localStorage.setItem('vk-theme',next);}catch(e){}});}
  document.addEventListener('click',function(event){var button=event.target.closest('[data-copy-link]');if(!button)return;var link=button.getAttribute('data-copy-link');navigator.clipboard.writeText(link).then(function(){var old=button.textContent;button.textContent='Copied!';setTimeout(function(){button.textContent=old;},1400);}).catch(function(){window.prompt('লিংক কপি করুন',link);});});
  var newsletter=document.querySelector('[data-newsletter]');
  if(newsletter){newsletter.addEventListener('submit',function(event){event.preventDefault();window.alert('Newsletter চালু হলে আপনাকে জানানো হবে।');newsletter.reset();});}
  var consent=document.querySelector('[data-consent]');
  if(consent){var choice='';try{choice=localStorage.getItem('vk-consent')||'';}catch(e){}if(!choice)consent.hidden=false;consent.addEventListener('click',function(event){if(event.target.matches('[data-consent-accept],[data-consent-close]')){try{localStorage.setItem('vk-consent',event.target.matches('[data-consent-accept]')?'accepted':'later');}catch(e){}consent.hidden=true;}});}
})();
