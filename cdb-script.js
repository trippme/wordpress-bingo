jQuery(document).ready(function($){
  $(document).on('click', '.cdb-tile', function(){
    var $tile = $(this),
        board = $tile.data('board'),
        idx   = $tile.data('tile');
    $.post(cdb_params.ajax_url, {
      action: 'cdb_toggle',
      board:  board,
      tile:   idx,
      nonce:  cdb_params.nonce
    }, function(res){
      if (res.success) {
        $tile.toggleClass('active');
      }
    });
  });
});