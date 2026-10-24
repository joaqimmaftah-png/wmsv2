  </main><!-- /.page -->
</div><!-- /#canvas -->
<script>
setTimeout(function(){
  var fl=document.querySelectorAll('.flash');
  for(var i=0;i<fl.length;i++){
    (function(f){
      f.style.transition='opacity .4s';
      f.style.opacity='0';
      setTimeout(function(){if(f.parentNode)f.parentNode.removeChild(f);},450);
    })(fl[i]);
  }
},5000);
</script>
</body>
</html>
