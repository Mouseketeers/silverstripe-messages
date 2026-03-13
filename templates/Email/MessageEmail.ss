$Body
<% if $Image %><p><% if $Image %>$Image.ScaleWidth(560)<% end_if %></p><% end_if %>
<% if $Video %><p> <a href="$Video.AbsoluteLink">Click here to  watch the video</a></p><% end_if %>