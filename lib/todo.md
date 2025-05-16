generic
trip cluster:
- [] do not create clusters that are not finished

video generator
- [ ] audio track of videos is not theres 

slidehow
- [] make sure selected images are not redundant
- [ ] add video (partials)
- [] transitions between media

in production
- [ ] iteratively add new trips vs always full recompute?
- [ ] the entity album already groups media. trips add only time  location space (which can be deducted from the media contained in an album)

password: occ files:scan --user



# hdbscan

- [] find the actual implementation on the internet
- find the users geografical center (localtion with most fotos taken)
- [] incrementaly add trips only based on images not already associated with a trip (keep existing trips)
- modify parameters so we dont split trips that are obviously belonging to the same trip (same location, adjacent time)
- cluster more aggresive the closer we get to the center (multiple short trips in close vicinity vs a single long trip  over some area and time far away from the center)
- 

