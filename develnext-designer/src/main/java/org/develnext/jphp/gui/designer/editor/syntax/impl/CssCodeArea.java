package org.develnext.jphp.gui.designer.editor.syntax.impl;

import java.util.Collection;
import java.util.Collections;
import org.antlr.v4.runtime.*;
import org.develnext.jphp.gui.designer.editor.syntax.AbstractCodeArea;
import org.develnext.jphp.gui.designer.editor.syntax.CodeAreaGutterNote;
import org.develnext.lexer.css.CSSLexer;
import org.develnext.lexer.css.CSSParser;
import org.fxmisc.richtext.*;

public class CssCodeArea extends AbstractCodeArea {
    private final BaseErrorListener errorListener = new BaseErrorListener() {
        @Override
        public void syntaxError(Recognizer<?, ?> recognizer, Object offendingSymbol, int line, int charPositionInLine, String msg, RecognitionException e) {
            getGutter().addNote(line, new CodeAreaGutterNote("error", msg));
        }
    };

    public CssCodeArea() {
        super();
        setStylesheet(AbstractCodeArea.class.getResource("CssCodeArea.css").toExternalForm());
    }

    private static Collection<String> getStyleOfToken(Token token) {
        switch (token.getType()) {
            case CSSParser.COMMENT:
                return Collections.singletonList("comment");
            case CSSParser.HEX_COLOR:
                return Collections.singletonList("color");
            case CSSParser.STRING:
                return Collections.singletonList("string");
            case CSSParser.NUMBER:
                return Collections.singletonList("number");
            case CSSParser.HASH:
            case CSSParser.CLASS:
                return Collections.singletonList("selector");
            case CSSParser.IDENT:
                return Collections.singletonList("keyword");
            default:
                switch (token.getText()) {
                    case "{":
                    case "}":
                    case ";":
                    case ":":
                        return Collections.singletonList("control");
                }

                return Collections.emptyList();
        }
    }

    @Override
    protected void computeHighlighting(StyleSpansBuilder<Collection<String>> spansBuilder, String text) {
        ANTLRInputStream inputStream = new ANTLRInputStream(text);
        CSSLexer lex = new CSSLexer(inputStream);
        //lex.addErrorListener(errorListener);

        int lastEnd = 0;
        for (Token token : lex.getAllTokens()) {
            int spacer = token.getStartIndex() - lastEnd;

            if (spacer > 0) {
                spansBuilder.add(Collections.emptyList(), spacer);
            }

            Collection<String> styleOfToken = getStyleOfToken(token);

            if (!styleOfToken.isEmpty()) {
                int gap = token.getStopIndex() - token.getStartIndex() + 1;
                spansBuilder.add(styleOfToken, gap);

                lastEnd = token.getStopIndex() + 1;
            }
        }

        lex.reset();
        CSSParser cssParser = new CSSParser(new CommonTokenStream(lex));
        cssParser.addErrorListener(errorListener);
        cssParser.styleSheet();
    }
}