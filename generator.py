# -*- coding: utf8 -*-
import argparse
import requests
import re
import sys
import bs4
import html5print

def re_advenced_replace(pattern, input, output_func, flags = None):
    output = input

    offset = 0
    matches = re.finditer(pattern, input, flags)
    for match in matches:    
        output_match = output_func(match)
    
        output = output[:match.start() + offset] + output_match + output[match.end() + offset:]
        offset += len(output_match) - (match.end() - match.start())
        
    return output
    
def cast_widthchar(input):
    output = ""
    for char in input:
        char_int = ord(char)
        start = 0
        start_correct = 0
        
        if ord("！") <= char_int and char_int <= ord("～"):
            start = ord("！")
            start_correct = ord("!")
        
        output += chr(char_int - start + start_correct)
        
    return output
    
def beautify_html(input):
    html = html5print.HTMLBeautifier.beautify(input, 4, formatter="lxml")
    html = re.sub(r"\r\n", "\n", html)                          # Else file write will double-interpret \r
    html = re.sub(r"(?<=>)\s+(?=[^<\s])", "", html)
    html = re.sub(r"(?<=[^>\s])\s+(?=<)", "", html)
    return html
def beautify_xml(content):
    xml = content.prettify(formatter="xml")
    xml = re.sub(r"(?<=>)\s+(?=[^<\s])", "", xml)
    xml = re.sub(r"(?<=[^>\s])\s+(?=<)", "", xml)
    xml = re_advenced_replace(r"^ +", xml, lambda match: "\t" * len(match.group(0)), re.IGNORECASE | re.MULTILINE)
    return xml
    
def format_chapter__status(match):
    name = re.sub(r"&#12288;", " ", match.group(1))
        
    hp = "{} / {}".format(cast_widthchar(match.group(2)), cast_widthchar(match.group(3)))
    if match.group(4):
        hp += " (Level up: +{})".format(cast_widthchar(match.group(4)))
        
    mp = "{} / {}".format(cast_widthchar(match.group(5)), cast_widthchar(match.group(6)))
    if match.group(7):
        mp += " (Level up: +{})".format(cast_widthchar(match.group(7)))
        
    sp1 = "{} / {}".format(cast_widthchar(match.group(8)), cast_widthchar(match.group(9)))
    if match.group(10):
        sp1 += " (Level up: +{})".format(cast_widthchar(match.group(10)))
        
    sp2 = "{} / {}".format(cast_widthchar(match.group(11)), cast_widthchar(match.group(12)))
    if match.group(13):
        sp2 += " +{}".format(cast_widthchar(match.group(13)))
    if match.group(14):
        sp2 += " (Level up: +{})".format(cast_widthchar(match.group(14)))
        
    offensive = "{}".format(cast_widthchar(match.group(15)))
    if match.group(16):
        offensive += " (Level up: +{})".format(cast_widthchar(match.group(16)))
        
    defensive = "{}".format(cast_widthchar(match.group(17)))
    if match.group(18):
        defensive += " (Level up: +{})".format(cast_widthchar(match.group(18)))
        
    magic = "{}".format(cast_widthchar(match.group(19)))
    if match.group(20):
        magic += " (Level up: +{})".format(cast_widthchar(match.group(20)))
        
    resistance = "{}".format(cast_widthchar(match.group(21)))
    if match.group(22):
        resistance += " (Level up: +{})".format(cast_widthchar(match.group(22)))
        
    speed = "{}".format(cast_widthchar(match.group(23)))
    if match.group(24):
        speed += " (Level up: +{})".format(cast_widthchar(match.group(24)))

    bloc = "</p>"
    bloc += "<table border=\"1\">"
    bloc += "<tbody>"
    bloc += "<tr>"
    bloc += "<td colspan=\"4\">{name}</td>"
    bloc += "</tr>"
    bloc += "<tr>"
    bloc += "<td colspan=\"4\">Status</td>"
    bloc += "</tr>"
    bloc += "<tr>"
    bloc += "<td>HP:</td>"
    bloc += "<td>{hp}</td>"
    bloc += "<td>Average Offensive Ability</td>"
    bloc += "<td>{offensive}</td>"
    bloc += "</tr>"
    bloc += "<tr>"
    bloc += "<td>MP :</td>"
    bloc += "<td>{mp}</td>"
    bloc += "<td>Average Defensive Ability</td>"
    bloc += "<td>{defensive}</td>"
    bloc += "</tr>"
    bloc += "<tr>"
    bloc += "<td>SP :</td>"
    bloc += "<td>{sp1}</td>"
    bloc += "<td>Average Magic Ability</td>"
    bloc += "<td>{magic}</td>"
    bloc += "</tr>"
    bloc += "<tr>"
    bloc += "<td/>"
    bloc += "<td>{sp2}</td>"
    bloc += "<td>Average Resistance Ability</td>"
    bloc += "<td>{resistance}</td>"
    bloc += "</tr>"
    bloc += "<tr>"
    bloc += "<td/>"
    bloc += "<td/>"
    bloc += "<td>Average Speed Ability:</td>"
    bloc += "<td>{speed}</td>"
    bloc += "</tr>"
    bloc += "</tbody>"
    bloc += "</table>"
    bloc += "<p>"
    
    return bloc.format( \
        name=name, \
        hp=hp, \
        mp=mp, \
        sp1=sp1, \
        sp2=sp2, \
        offensive=offensive, \
        defensive=defensive, \
        magic=magic, \
        resistance=resistance, \
        speed=speed \
    )
def format_chapter__skills(match):
    skills = re.sub(r"(?:&#12300;([^&]+?)&#12301;)", "[\\1]<br_manual />", match.group(1))
    if match.group(2):
        skills += "[n % I = W]"
    else:
        skills = skills[:len(skills) - len("<br_manual />")]
    if match.group(3):
        skills += "<br_manual />Skill Points: {}".format(cast_widthchar(match.group(3)))

    bloc = "</p>"
    bloc += "<h3>Skill</h3>"
    bloc += "<p>{skills}</p>"
    bloc += "<p>"
    
    return bloc.format( \
        skills=skills
    )
def format_chapter__titles(match):
    titles = re.sub(r"(?:&#12300;([^&]+?)&#12301;)", "[\\1]<br_manual />", match.group(1))
    titles = titles[:len(titles) - len("<br_manual />")]
    
    bloc = "</p>"
    bloc += "<h3>Title</h3>"
    bloc += "<p>{titles}</p>"
    bloc += "<p>"
    
    return bloc.format( \
        titles=titles
    )
def format_chapter(body):
    body = re_advenced_replace( \
        r"<br />\n<br />\n&#12302;(.+?(?:&#12288;(?:.+?))*)<br />\nStatus<br />\n&#12288;ＨＰ&#65306;([\uFF10-\uFF19]+)&#65295;([\uFF10-\uFF19]+)&#65288;Green&#65289;(?:&#65288;([\uFF10-\uFF19]+)ｕｐ&#65289;)?<br />\n&#12288;ＭＰ&#65306;([\uFF10-\uFF19]+)&#65295;([\uFF10-\uFF19]+)&#65288;Blue&#65289;(?:&#65288;([\uFF10-\uFF19]+)ｕｐ&#65289;)?<br />\n&#12288;ＳＰ&#65306;([\uFF10-\uFF19]+)&#65295;([\uFF10-\uFF19]+)&#65288;Yellow&#65289;(?:&#65288;([\uFF10-\uFF19]+)ｕｐ&#65289;)?<br />\n&#12288;&#12288;&#12288;&#65306;([\uFF10-\uFF19]+)&#65295;([\uFF10-\uFF19]+)&#65288;Red&#65289;(?:&#65291;([\uFF10-\uFF19]+))?(?:&#65288;([\uFF10-\uFF19]+)ｕｐ&#65289;)?<br />\n&#12288;Average Offensive Ability&#65306;([\uFF10-\uFF19]+)(?:&#65288;([\uFF10-\uFF19]+)ｕｐ&#65289;)?<br />\n&#12288;Average Defensive Ability&#65306;([\uFF10-\uFF19]+)(?:&#65288;([\uFF10-\uFF19]+)ｕｐ&#65289;)?<br />\n&#12288;Average Magic Ability&#65306;([\uFF10-\uFF19]+)(?:&#65288;([\uFF10-\uFF19]+)ｕｐ&#65289;)?<br />\n&#12288;Average Resistance Ability&#65306;([\uFF10-\uFF19]+)(?:&#65288;([\uFF10-\uFF19]+)ｕｐ&#65289;)?<br />\n&#12288;Average Speed Ability&#65306;([\uFF10-\uFF19]+)(?:&#65288;([\uFF10-\uFF19]+)ｕｐ&#65289;)?<br />\n", \
        body, \
        format_chapter__status, \
        re.IGNORECASE \
    )
    body = re_advenced_replace( \
        r"Skill<br />\n&#12288;((?:&#12300;[^&]+?&#12301;)+)(?:(&#12300;ｎ&#65285;Ｉ&#65309;Ｗ&#12301;)|(?:&#12303;))<br />\n(?:&#12288;Skill points&#65306;([\uFF10-\uFF19]+))?<br />\n", \
        body, \
        format_chapter__skills, \
        re.IGNORECASE \
    )
    body = re_advenced_replace( \
        r"Title<br />\n&#12288;((?:&#12300;[^&]+?&#12301;)+)&#12303;<br />\n", \
        body, \
        format_chapter__titles, \
        re.IGNORECASE \
    )
    
    matches = re.finditer(r"([\uFF01-\uFF5E]+)", body)
    for match in matches:
        body = body[:match.start()] + cast_widthchar(match.group(1)) + body[match.end():]
    
    body = re.sub(r"&#12302;(.+?)&#12303;", "[\\1]", body)                          # Replace [] for competance's name
    body = re.sub(r"&#12298;(.+?)&#12299;", "{\\1}", body)                          # Replace {} for "system" voice
    
    if re.search(r"<div>\s*<br />\s*</div>", body, re.IGNORECASE):        
        body = re.sub(r"</div>\s*(?:<div>\s*<br />\s*</div>\s*){3,}\s*<div>\s*", "</p><p>***</p><p>", body)         # Replace long paragraph interval
        body = re.sub(r"</div>\s*<div>\s*<br />\s*</div>\s*<div>\s*", "</p><p>", body)                              # Replace paragraph interval
        body = re.sub(r"</div>\s*<div>\s*", " ", body)                                                              # Replace line break
        
        # Replace 「」 for dialog
        body = re.sub(r"&#12301;\s*&#12300;", "\"<br_manual />\"", body)
        body = re.sub(r"&#1230[01];", "\"", body)
    else:
        body = re.sub(r"&#12300;(.+?)&#12301;<br />", "\"\\1\"<br_manual />", body)             # Replace 「」 for dialog
        
        body = re.sub(r"(?:<br />\s*){3,}", "</p><p>***</p><p>", body)          # Replace long paragraph interval
        body = re.sub(r"(?:<br />\s*){2}", "</p><p>", body)                     # Replace paragraph interval
        body = re.sub(r"<br />\s*", " ", body)                                  # Replace line break
    
    body = re.sub(r"<br_manual />", "<br />", body)                         # Replace manual line break
    body = re.sub(r"<p>\s*</p>", "", body)                                  # Replace empty paragraph
    
    body = re.sub(r"</?(?:a|b|span|div)(?: .+?)?>", "", body)               # Delete unused tags
    body = re.sub(r"&nbsp;", " ", body)                                     # Delete unbreakable spaces

    body = re.sub(r"^\s+", "", body)                                        # Remove spaces at start
    body = re.sub(r"\s+$", "", body)                                        # Remove spaces at end
    
    return body
    
def generate_chapter(body, chapter, title):
    body = format_chapter(body)
    
    part = "<html xmlns=\"http://www.w3.org/1999/xhtml\" xmlns:epub=\"http://www.idpf.org/2007/ops\" xml:lang=\"en\" lang=\"en\">"
    part += "<head>"
    part += "<title>{chapter_long} - {title}</title>"
    part += "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />"
    part += "<link href=\"../style/text.css\" rel=\"stylesheet\" type=\"text/css\" />"
    part += "</head>"
    part += "<body id=\"body-{chapter_short}\" class=\"calibre\">"
    part += "<h2>{chapter_long} - {title}</h2>"
    part += "<p>{body}</p>"
    part += "</body>"
    part += "</html>"
    
    part = part.format( \
        title=title, \
        chapter_short=chapter["short"], \
        chapter_long=chapter["long"], \
        body=body \
    )
    
    part = beautify_html(part)
    part = "<?xml version='1.0' encoding='utf-8'?>\n" + part        # Done AFTER because not part of HTML's standard
    
    file = open("text/part0{}.html".format(chapter["file"]), "w", encoding="utf-8")
    file.write(part)
    file.close
def reference_chapter(chapter):
    FILENAME = "content.opf"

    file = open(FILENAME, "r", encoding="utf-8")
    content_raw = file.read()
    file.close
    
    content = bs4.BeautifulSoup(content_raw, "xml")
    
    manifest = content.select("package manifest")[0]
    
    item = content.new_tag("item", href="text/part0{}.html".format(chapter["file"]), id="page-{}".format(chapter["short"]), attrs={"media-type": "application/xhtml+xml"})
    item_exist = manifest.find(name="item", id="page-{}".format(chapter["short"]))
    if item_exist:
        item_exist.replace_with(item)
    else:
        manifest.append(item)
        
    spine = content.select("package spine")[0]
    
    itemref = content.new_tag("itemref", idref="page-{}".format(chapter["short"]))
    itemref_exist = spine.find(name="itemref", idref="page-{}".format(chapter["short"]))
    if itemref_exist:
        itemref_exist.replace_with(itemref)
    else:
        spine.append(itemref)
        
    content_raw = beautify_xml(content)
        
    file = open(FILENAME, "w", encoding="utf-8")
    file.write(content_raw)
    file.close
def summarize_chapter(chapter, title):
    FILENAME = "toc.ncx"

    file = open(FILENAME, "r", encoding="utf-8")
    summary_raw = file.read()
    file.close
    
    summary = bs4.BeautifulSoup(summary_raw, "xml")
    
    # Create navPoint
    text = summary.new_tag("text")
    text.string = "{} - {}".format(chapter["long"], title)
    
    label = summary.new_tag("navLabel")
    label.append(text)
    
    point = summary.new_tag("navPoint", id="num-{}".format(chapter["short"]), playOrder=chapter["name"], attrs={"class": "chapter"})
    point.append(label)
    point.append(summary.new_tag("content", src="text/part0{}.html#body-{}".format(chapter["file"], chapter["short"])))
    
    navmap = summary.select("ncx navMap")[0]
    navpoint = navmap.find(name="navPoint", id="num-{}".format(chapter["short"]))
    if navpoint:
        navpoint.replace_with(point)
    else:
        navmap.append(point)

    summary_raw = beautify_xml(summary)
        
    file = open(FILENAME, "w", encoding="utf-8")
    file.write(summary_raw)
    file.close
        
def parse_chapter(chapter_name, url):
    print("Parsing chapter #{} : {}".format(chapter_name, url))
    
    chapter = {"name": chapter_name};
    
    # Get URL content
    query = requests.get(url)
    if query.status_code != requests.codes.ok:
        print("Failed to get file : {}".format(query.status_code))
        return
        
    # Match for chapter title
    match = re.search(r"<h3 class='post-title entry-title' itemprop='name'>\s*Kumo Desu [gk]a, Nani [kg]a\? (.+?)\s*</h3>", query.text, re.IGNORECASE | re.DOTALL)
    if not match:
        print("Failed to extract title")
        return
    title = match.group(1)
    print("Raw title : {}".format(title))
    
    # Match for chapter number
    match = re.search(r"^Chapter ([0-9]+)$", title, re.IGNORECASE)
    if match:
        if chapter["name"] == "0":
            chapter["name"] = match.group(1)
        
        if chapter["name"] != match.group(1):
            print("Chapter number mismatched : {} vs {}".format(chapter["name"], match.group(1)))
            return
            
        match = re.search(r"<b><span style=\"font-size: large;\">(?:[0-9]+(?:\.[0-9]+)?) (.+?)</span></b>", query.text, re.IGNORECASE)
        if match:
            title = re.sub(r"&nbsp;", "", match.group(1))
    else:
        if chapter["name"] == "0":
            print("Missing chapter number")
            return
        
    # Extract chapter body
    match = re.search(r"<div class='post-body entry-content' id='post-body-[0-9]+' itemprop='description articleBody'>\s*(.+?)</div>\s*<div class='post-footer'>", query.text, re.IGNORECASE | re.DOTALL)
    if not match:
        print("Failed to extract body")
        return
    body = match.group(1)
    
    match = re.match(r"^Chapter ([A-Z][0-9]+)$", title, re.IGNORECASE)
    if match:
        match_title = re.search(r"<b><span style=\"font-size: large;\">(" + match.group(1) + " [^<]+)</span></b>", body, re.IGNORECASE)
        if match_title:
            title = cast_widthchar(match_title.group(1))

    print("Chapter : {}".format(chapter["name"]))
    print("Title : {}".format(title))
    
    body = re.sub(r"^.+<b><span style=\"font-size: large;\">[^<]+<\/span></b><br />\s*<br />", "", body, flags = re.IGNORECASE | re.DOTALL)        # Delete first part (all to chapter's title)
    
    try:
        pos = str(chapter["name"]).index(".")
    except ValueError:
        chapter["short"] = chapter["name"]
        chapter["long"] = "{:0>3}".format(chapter["name"])
        chapter["file"] = "{:0>3}".format(chapter["name"])
    else:
        chapter["short"] = chapter["name"].replace(".", "-")
        chapter["long"] = "{:0>3}".format(chapter["name"][0:pos]) + "." + chapter["name"][pos+1:]
        chapter["file"] = "{:0>3}".format(chapter["name"][0:pos]) + "-" + chapter["name"][pos+1:]

    print("")
    generate_chapter(body, chapter, title)                  # Save chapter's HTML
    print("Generation.........OK")
    reference_chapter(chapter)                              # Add chapter to ebook referential
    print("Referencing........OK")
    summarize_chapter(chapter, title)                       # Add chapter to summary
    print("Summarizing........OK")
  
parser = argparse.ArgumentParser(description="Chapter processor")
parser.add_argument("-c", "--chapter", help="Chapter's number", default="0")
parser.add_argument("url", help="Chapter's url to process")
args = parser.parse_args()

parse_chapter(args.chapter, args.url)